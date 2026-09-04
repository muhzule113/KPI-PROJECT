<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Modules\Assessment\AssessmentService;
use App\Modules\Import\CashierImportService;
use App\Modules\Review\ReviewService;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class KpiWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected AssessmentService $assessmentService;
    protected ReviewService $reviewService;
    protected ApprovalService $approvalService;
    protected CashierImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessmentService = app(AssessmentService::class);
        $this->reviewService = app(ReviewService::class);
        $this->approvalService = app(ApprovalService::class);
        $this->importService = app(CashierImportService::class);
    }

    public function test_full_teknisi_kpi_lifecycle(): void
    {
        $teknisiUser = User::where('email', 'teknisi@toko.com')->first();
        $spvUser = User::where('email', 'supervisor@toko.com')->first();
        $managerUser = User::where('email', 'manager@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $teknisiEmp = Employee::where('user_id', $teknisiUser->id)->first();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $teknisiEmp->id)
            ->first();

        $this->assertNotNull($kpi);
        $this->assertEquals('draft', $kpi->status);

        // 1. Supervisor supplies the non-rubric values; employee has no write path
        foreach ($kpi->items as $item) {
            if ($item->formula_key_snapshot !== 'rubric') {
                $targetVal = $item->target_value_snapshot ?? 100;
                $this->assessmentService->saveItemDraft($item, (float) $targetVal, null, 'Test input', $spvUser->id);
            }
        }

        // Upload evidence for item that requires it (e.g. TEK-01)
        $evidenceItem = $kpi->items()->where('evidence_req_snapshot', true)->first();
        if ($evidenceItem) {
            $fakeFile = UploadedFile::fake()->create('laporan_servis.pdf', 500, 'application/pdf');
            $evidence = $this->assessmentService->uploadEvidence($evidenceItem, $fakeFile, 'Bukti servis bulanan', $spvUser->id);
            $evidence->update([
                'scan_status' => 'clean',
                'scanned_at' => now(),
                'scan_note' => 'Test scanner menyatakan file aman.',
            ]);
        }

        // 2. Submit KPI through the assigned Supervisor
        $submitRes = $this->assessmentService->submitKpi($kpi, $spvUser->id);
        $this->assertTrue($submitRes['success']);
        $kpi->refresh();
        $this->assertEquals('submitted', $kpi->status);

        // 3. Supervisor reviews and grades rubric items
        foreach ($kpi->items as $item) {
            if ($item->formula_key_snapshot === 'rubric' && !empty($item->rubric_snapshot['criteria'])) {
                $answers = [];
                foreach ($item->rubric_snapshot['criteria'] as $c) {
                    $answers[] = [
                        'criterion_id' => $c['id'] ?? 1,
                        'criterion_text' => $c['criterion_text'],
                        'points' => (float) $c['points'],
                        'is_fulfilled' => true,
                    ];
                }
                $this->reviewService->submitRubricAssessment($item, $answers, $spvUser->id);
            } else {
                $this->reviewService->verifyItem($item, 'valid', 'Data valid dan sesuai', null, $spvUser->id);
            }
        }

        // 4. Supervisor forwards to Manager
        $fwdRes = $this->reviewService->forwardToManager($kpi, 'Semua data telah diverifikasi lengkap', $spvUser->id);
        $this->assertTrue($fwdRes['success']);
        $kpi->refresh();
        $this->assertEquals('pending_approval', $kpi->status);
        $this->assertGreaterThan(0, $kpi->final_score);

        // 5. Manager Approves KPI
        $appRes = $this->approvalService->approve($kpi, 'Disetujui, kinerja sangat baik', $managerUser->id);
        $this->assertTrue($appRes['success']);
        $kpi->refresh();
        $this->assertEquals('approved', $kpi->status);
        $this->assertNotNull($kpi->approved_at);
        $this->assertNotNull($kpi->locked_at);
    }

    public function test_no_self_approval_security_policy(): void
    {
        $spvUser = User::where('email', 'supervisor@toko.com')->first();
        $spvEmp = Employee::where('user_id', $spvUser->id)->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        // Get Supervisor's own KPI
        $spvKpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $spvEmp->id)
            ->first();

        if ($spvKpi) {
            $spvKpi->update(['status' => 'pending_approval']);

            // Expect Exception when Supervisor attempts to approve their own KPI
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Pemisahan tugas (No Self-Approval)');
            $this->approvalService->approve($spvKpi, 'Self approval attempt', $spvUser->id);
        }
    }

    public function test_manager_can_assess_all_kpi_items_before_approval(): void
    {
        $managerUser = User::where('email', 'manager@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $teknisiEmp = Employee::whereHas('user', fn ($query) => $query->where('email', 'teknisi@toko.com'))->first();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $teknisiEmp->id)
            ->firstOrFail();

        $kpi->update(['status' => 'pending_approval']);
        $kpi->items()->update(['status' => 'verified']);

        $this->actingAs($managerUser, 'sanctum');

        foreach ($kpi->items()->get() as $item) {
            if ($item->formula_key_snapshot === 'rubric') {
                $answers = collect($item->rubric_snapshot['criteria'] ?? [])
                    ->map(fn (array $criterion): array => [
                        'criterion_id' => $criterion['id'],
                        'is_fulfilled' => true,
                    ])->all();

                $this->postJson(
                    "/api/v1/manager/approval/{$kpi->id}/items/{$item->id}/rubric",
                    ['answers' => $answers]
                )->assertOk()->assertJsonPath('success', true);
            } else {
                $this->postJson(
                    "/api/v1/manager/approval/{$kpi->id}/items/{$item->id}/assess",
                    ['actual_decimal' => (float) ($item->target_value_snapshot ?? 0)]
                )->assertOk()->assertJsonPath('success', true);
            }
        }

        $this->assertSame(0, $kpi->items()->where('status', '!=', 'assessed')->count());

        $this->postJson("/api/v1/manager/approval/{$kpi->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_duplicate_import_hash_rejection(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $adminUser = User::where('email', 'admin@kpi.com')->first();

        // Create dummy CSV content
        $csvContent = "No Invoice,Tanggal,Nama Kasir,Grand Total,Kas Sistem,Kas Aktual,Durasi (detik),Status\n" .
                      "INV-001,2026-08-10,Rian Pratama,150000,150000,150000,45,SUCCESS\n" .
                      "INV-002,2026-08-11,Rian Pratama,250000,250000,250000,60,SUCCESS\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'test_import') . '.csv';
        file_put_contents($tempFile, $csvContent);

        $uploadedFile1 = new UploadedFile($tempFile, 'laporan_kasir_aug.csv', 'text/csv', null, true);
        $batch1 = $this->importService->uploadAndStage($uploadedFile1, $period, null, $adminUser->id);
        $this->assertEquals('ready_for_preview', $batch1->status);

        // Confirm batch 1
        $this->importService->confirmAndCommit($batch1, $adminUser->id);
        $this->assertEquals('confirmed', $batch1->fresh()->status);

        // Try importing exact same file again -> Must throw Exception
        $uploadedFile2 = new UploadedFile($tempFile, 'laporan_kasir_aug_copy.csv', 'text/csv', null, true);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File ini identik (hash SHA-256 sama)');
        $this->importService->uploadAndStage($uploadedFile2, $period, null, $adminUser->id);
    }
}
