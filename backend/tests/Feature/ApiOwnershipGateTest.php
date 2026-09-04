<?php

namespace Tests\Feature;

use App\Jobs\ParseCashierImport;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Modules\Import\CashierImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiOwnershipGateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeTicket(?string $technicianEmail = null): ServiceTicket
    {
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $technicianId = null;
        if ($technicianEmail) {
            $technicianId = Employee::where('email', $technicianEmail)->first()->id;
        }

        return ServiceTicket::create([
            'ticket_number' => 'SRV-OWN-' . random_int(1000, 9999),
            'customer_name' => 'Test Customer',
            'customer_phone' => '08123456789',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Layar retak',
            'status' => 'in_progress',
            'technician_employee_id' => $technicianId,
            'branch_id' => 1,
            'period_id' => $period->id,
        ]);
    }

    // ---- requestSparepart: ownership ----

    public function test_technician_cannot_request_sparepart_for_other_technician_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $otherTekEmp = Employee::where('email', 'cs@toko.com')->first();
        $part = Sparepart::first();

        $ticketOther = ServiceTicket::create([
            'ticket_number' => 'SRV-OWN-OTHER-' . random_int(1000, 9999),
            'customer_name' => 'Customer Lain',
            'customer_phone' => '08987654321',
            'device_brand' => 'Samsung',
            'device_model' => 'A54',
            'initial_complaint' => 'Baterai cepat habis',
            'status' => 'in_progress',
            'technician_employee_id' => $otherTekEmp->id,
            'branch_id' => 1,
        ]);

        $this->actingAs($userTek, 'sanctum')
            ->postJson('/api/v1/operational/spareparts/request', [
                'service_ticket_id' => $ticketOther->id,
                'sparepart_id' => $part->id,
            ])
            ->assertForbidden();
    }

    public function test_technician_can_request_sparepart_for_own_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $part = Sparepart::first();

        $ticket = $this->makeTicket('teknisi@toko.com');

        $this->actingAs($userTek, 'sanctum')
            ->postJson('/api/v1/operational/spareparts/request', [
                'service_ticket_id' => $ticket->id,
                'sparepart_id' => $part->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('sparepart_requests', [
            'service_ticket_id' => $ticket->id,
            'technician_employee_id' => $empTek->id,
        ]);
    }

    // ---- sparepartRequests list: warehouse gate ----

    public function test_technician_cannot_view_pending_sparepart_requests(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();

        $this->actingAs($userTek, 'sanctum')
            ->getJson('/api/v1/operational/sparepart-requests')
            ->assertForbidden();
    }

    public function test_gudang_can_view_pending_sparepart_requests(): void
    {
        $userGud = User::where('email', 'gudang@toko.com')->first();

        $this->actingAs($userGud, 'sanctum')
            ->getJson('/api/v1/operational/sparepart-requests')
            ->assertOk();
    }

    // ---- fulfillSparepart: warehouse gate ----

    public function test_technician_cannot_fulfill_sparepart_request(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $part = Sparepart::first();
        $ticket = $this->makeTicket('teknisi@toko.com');

        $req = SparepartRequest::create([
            'service_ticket_id' => $ticket->id,
            'sparepart_id' => $part->id,
            'technician_employee_id' => $empTek->id,
            'quantity' => 1,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/spareparts/fulfill/{$req->id}")
            ->assertForbidden();
    }

    public function test_gudang_can_fulfill_sparepart_request(): void
    {
        $userGud = User::where('email', 'gudang@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $part = Sparepart::first();
        $ticket = $this->makeTicket('teknisi@toko.com');

        $req = SparepartRequest::create([
            'service_ticket_id' => $ticket->id,
            'sparepart_id' => $part->id,
            'technician_employee_id' => $empTek->id,
            'quantity' => 1,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($userGud, 'sanctum')
            ->postJson("/api/v1/operational/spareparts/fulfill/{$req->id}")
            ->assertOk();

        $this->assertEquals('fulfilled', $req->fresh()->status);
    }

    // ---- pickupAndFeedback: CS-only gate ----

    public function test_technician_cannot_deliver_and_rate_own_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $ticket = $this->makeTicket('teknisi@toko.com');
        $ticket->update(['status' => 'completed']);

        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/feedback", [
                'rating' => 5,
                'comments' => 'self rating',
                'follow_up_ontime' => true,
            ])
            ->assertForbidden();
    }

    public function test_cs_can_deliver_and_rate_ticket(): void
    {
        $userCs = User::where('email', 'cs@toko.com')->first();
        $ticket = $this->makeTicket('teknisi@toko.com');
        $ticket->update([
            'status' => 'completed',
            'intake_by_employee_id' => Employee::where('email', 'cs@toko.com')->value('id'),
        ]);

        $this->actingAs($userCs, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/feedback", [
                'rating' => 5,
                'comments' => 'Pelayanan bagus',
                'feedback_channel' => 'in_store',
                'follow_up_ontime' => true,
            ])
            ->assertOk();
    }

    // ---- Cashier import: role gate ----

    public function test_technician_cannot_upload_cashier_report(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $file = UploadedFile::fake()->create('laporan.xlsx', 100);

        $this->actingAs($userTek, 'sanctum')
            ->postJson('/api/v1/cashier/import', ['file' => $file])
            ->assertForbidden();
    }

    public function test_cashier_import_is_queued_and_can_be_polled(): void
    {
        Queue::fake();
        Storage::fake('local');

        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'laporan.csv',
            "No Invoice,Tanggal,Nama Kasir,Grand Total,Kas Sistem,Kas Aktual,Durasi (detik),Status\n"
                . "INV-QUEUE-001,2026-08-10,Rian Pratama,150000,150000,150000,45,SUCCESS\n"
        );

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/cashier/import', [
                'file' => $file,
                'period_id' => $period->id,
            ]);

        $response->assertOk()->assertJsonPath('data.status', 'parsing');
        $batchId = $response->json('data.batch_id');

        Queue::assertPushed(ParseCashierImport::class, fn (ParseCashierImport $job): bool => $job->batchId === $batchId);

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/cashier/import/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'parsing');

        (new ParseCashierImport($batchId))->handle(app(CashierImportService::class));

        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'status' => 'ready_for_preview',
            'valid_rows' => 1,
        ]);
    }

    public function test_kasir_cannot_confirm_other_persons_batch(): void
    {
        $userKasir = User::where('email', 'kasir@toko.com')->first();
        $managerUser = User::where('email', 'manager@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $batch = ImportBatch::create([
            'file_name' => 'report.xlsx',
            'file_path' => 'uploads/x.xlsx',
            'file_hash_sha256' => hash('sha256', random_bytes(16)),
            'period_id' => $period->id,
            'uploader_id' => $managerUser->id,
            'status' => 'staged',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
        ]);

        $this->actingAs($userKasir, 'sanctum')
            ->postJson("/api/v1/cashier/import/{$batch->id}/confirm")
            ->assertForbidden();
    }

    public function test_manager_passes_cashier_confirm_role_and_ownership_gate(): void
    {
        $managerUser = User::where('email', 'manager@toko.com')->first();
        $kasirUser = User::where('email', 'kasir@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $batch = ImportBatch::create([
            'file_name' => 'report.xlsx',
            'file_path' => 'uploads/x.xlsx',
            'file_hash_sha256' => hash('sha256', random_bytes(16)),
            'period_id' => $period->id,
            'uploader_id' => $kasirUser->id, // milik kasir, bukan manager
            'status' => 'staged',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
        ]);

        // Manager lolos role gate & ownership check (boleh konfirmasi batch orang lain),
        // jadi respons bukan 403 — diteruskan ke service. Batch tanpa data siap akan ditolak
        // service dengan 422 (bukan 403), membuktikan gate role/ownership dilalui.
        $this->actingAs($managerUser, 'sanctum')
            ->postJson("/api/v1/cashier/import/{$batch->id}/confirm")
            ->assertStatus(422);
    }
}
