<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\KpiPeriod;
use App\Models\Position;
use App\Models\User;
use App\Modules\Import\CashierImportService;
use App\Modules\Security\FileScanService;
use App\Support\SpreadsheetValue;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MvpCompletionPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $payload = ['email' => 'rate-limit@example.test', 'password' => 'salah'];

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
    }

    public function test_password_reset_is_single_use_and_revokes_all_mobile_sessions(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $user->createToken('Perangkat A', ['platform:mobile']);
        $user->createToken('Perangkat B', ['platform:mobile']);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'Password-Baru-123', 'password_confirmation' => 'Password-Baru-123'];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('Password-Baru-123', $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_session_list_and_targeted_revoke_are_scoped_to_current_user(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $other = User::where('email', 'cs@toko.com')->firstOrFail();
        $ownToken = $user->createToken('Perangkat sendiri', ['platform:mobile'])->accessToken;
        $otherToken = $other->createToken('Perangkat orang lain', ['platform:mobile'])->accessToken;

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/sessions')->assertOk()
            ->assertJsonFragment(['device_name' => 'Perangkat sendiri']);
        $this->deleteJson('/api/v1/auth/sessions/mobile/'.$otherToken->id)->assertNotFound();
        $this->deleteJson('/api/v1/auth/sessions/mobile/'.$ownToken->id)->assertOk();
    }

    public function test_scanner_fails_closed_and_spreadsheet_strings_are_sanitized(): void
    {
        config()->set('services.clamav.fake_result', 'unavailable');
        $this->expectException(RuntimeException::class);
        try {
            app(FileScanService::class)->scan(__FILE__);
        } finally {
            $this->assertSame("'=SUM(A1:A2)", SpreadsheetValue::safe('=SUM(A1:A2)'));
            $this->assertSame("'\tformula", SpreadsheetValue::safe("\tformula"));
            $this->assertSame('aman', SpreadsheetValue::safe('aman'));
        }
    }

    public function test_text_pdf_is_parsed_and_scanned_pdf_requires_review(): void
    {
        Storage::fake('local');
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $csv = "No Invoice,Tanggal,Nama Kasir,Grand Total,Kas Sistem,Kas Aktual,Durasi (detik),Status\n"
            .'PDF-001,2026-08-10,Rian Pratama,100000,100000,100000,60,SUCCESS';
        $textPdf = Pdf::loadHTML('<pre>'.htmlspecialchars($csv).'</pre>')->output();

        $batch = app(CashierImportService::class)->uploadAndStage(
            UploadedFile::fake()->createWithContent('laporan.pdf', $textPdf), $period, uploaderId: $cashier->id,
        );
        $this->assertSame('ready_for_preview', $batch->status, json_encode($batch->issues_json));
        $this->assertSame('clean', $batch->scan_status);
        $this->assertSame('IDR', $batch->currency);
        $this->assertSame($cashier->employee->branch_id, $batch->branch_id);

        $blankPdf = Pdf::loadHTML('<html><body></body></html>')->output();
        $scan = app(CashierImportService::class)->uploadAndStage(
            UploadedFile::fake()->createWithContent('scan.pdf', $blankPdf), $period, uploaderId: $cashier->id,
        );
        $this->assertSame('needs_review', $scan->status);
        $this->assertSame('ocr_required', $scan->issues_json[0]['code']);
    }

    public function test_duplicate_hash_is_rejected_and_ambiguous_cashier_needs_mapping(): void
    {
        Storage::fake('local');
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $header = "No Invoice,Tanggal,Nama Kasir,Grand Total,Kas Sistem,Kas Aktual,Durasi (detik),Status\n";
        $content = $header."DUP-001,2026-08-10,Rian Pratama,100000,100000,100000,60,SUCCESS\n";
        $service = app(CashierImportService::class);
        $service->uploadAndStage(UploadedFile::fake()->createWithContent('first.csv', $content), $period, uploaderId: $cashier->id);
        try {
            $service->uploadAndStage(UploadedFile::fake()->createWithContent('same.csv', $content), $period, uploaderId: $cashier->id);
            $this->fail('Hash file duplikat harus ditolak.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('hash SHA-256 sama', $exception->getMessage());
        }

        $duplicate = Employee::create([
            'employee_number' => 'EMP-DUP-KSR', 'name' => $cashier->employee->name, 'email' => 'cashier.duplicate@example.test',
            'position_id' => Position::where('code', 'POS-KSR')->value('id'), 'branch_id' => $cashier->employee->branch_id,
            'joined_at' => '2026-01-01', 'status' => 'active',
        ]);
        EmployeePlacement::create([
            'employee_id' => $duplicate->id, 'position_id' => $duplicate->position_id, 'branch_id' => $duplicate->branch_id,
            'effective_from' => '2026-01-01',
        ]);
        $batch = $service->uploadAndStage(
            UploadedFile::fake()->createWithContent('ambiguous.csv', $header."AMB-001,2026-08-10,Rian Pratama,100000,100000,100000,60,SUCCESS\n"),
            $period, uploaderId: $cashier->id,
        );
        $this->assertSame('needs_mapping', $batch->status);
        $this->assertContains('cashier_ambiguous', collect($batch->issues_json)->pluck('code')->all());
    }
}
