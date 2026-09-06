<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Service\ServiceTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function employee(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function ticket(string $status = 'diagnosing'): ServiceTicket
    {
        $technician = $this->employee('teknisi@toko.com')->employee;

        return ServiceTicket::create([
            'ticket_number' => 'SRV-WORKFLOW-'.str()->uuid(),
            'customer_name' => 'Pelanggan workflow',
            'customer_phone' => '081234567891',
            'device_brand' => 'Samsung',
            'device_model' => 'A54',
            'initial_complaint' => 'Layar retak',
            'branch_id' => $technician->branch_id,
            'technician_employee_id' => $technician->id,
            'intake_by_employee_id' => $this->employee('cs@toko.com')->employee->id,
            'status' => $status,
            'customer_consent_status' => 'approved',
            'sla_baseline_due_at' => now()->addDays(3),
        ]);
    }

    public function test_technician_requests_warehouse_fulfills_and_technician_confirms_once(): void
    {
        $technician = $this->employee('teknisi@toko.com');
        $warehouse = $this->employee('gudang@toko.com');
        $ticket = $this->ticket();
        $ticket->update(['passcode_or_pattern' => 'private-device-code', 'technical_evidence_json' => [['file_path' => 'private/qc.pdf']]]);
        $part = Sparepart::create([
            'code' => 'PART-WORKFLOW', 'name' => 'Layar A54', 'category' => 'LCD',
            'product_type' => 'sparepart', 'branch_id' => $ticket->branch_id,
            'stock_quantity' => 5, 'purchase_price' => 100, 'selling_price' => 200,
        ]);
        $request = $this->actingAs($technician, 'sanctum')
            ->postJson('/api/v1/operational/spareparts/request', [
                'service_ticket_id' => $ticket->id, 'sparepart_id' => $part->id,
                'quantity' => 2, 'row_version' => 1,
            ])->assertOk()->json('data');
        $ticket->refresh();
        $this->assertSame('waiting_sparepart', $ticket->status);
        $this->assertSame(2, $ticket->row_version);

        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/spareparts/fulfill/{$request['id']}", ['row_version' => 2])->assertForbidden();
        $this->actingAs($warehouse, 'sanctum')->postJson("/api/v1/operational/spareparts/fulfill/{$request['id']}", ['row_version' => 1])->assertConflict();
        $this->assertSame(5, $part->fresh()->stock_quantity);
        $this->actingAs($warehouse, 'sanctum')->postJson("/api/v1/operational/spareparts/fulfill/{$request['id']}", ['row_version' => 2])->assertOk()
            ->assertJsonPath('data.warehouse_employee_id', $warehouse->employee->id)
            ->assertJsonMissingPath('data.ticket.passcode_or_pattern')
            ->assertJsonMissingPath('data.ticket.technical_evidence_json');
        $this->assertSame(3, $part->fresh()->stock_quantity);
        $this->actingAs($warehouse, 'sanctum')->postJson("/api/v1/operational/spareparts/fulfill/{$request['id']}", ['row_version' => 2])->assertConflict();
        $this->assertSame(3, $part->fresh()->stock_quantity);

        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'in_progress', 'row_version' => 3])->assertUnprocessable();
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/spareparts/confirm/{$request['id']}", ['row_version' => 3])->assertOk();
        $this->assertSame(4, $ticket->fresh()->row_version);
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'in_progress', 'row_version' => 4])->assertOk();
        $this->assertSame(1, StockMovement::where('reference_type', 'sparepart_request')->where('reference_id', $request['id'])->count());
    }

    public function test_technical_work_requires_technician_assignment_consent_and_current_version(): void
    {
        $ticket = $this->ticket('intake');
        $technician = $this->employee('teknisi@toko.com');
        foreach (['cs@toko.com', 'kasir@toko.com', 'manager@toko.com', 'admin@kpi.com'] as $email) {
            $this->actingAs($this->employee($email), 'sanctum')
                ->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'diagnosing', 'row_version' => 1])
                ->assertForbidden();
        }
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'qc_ready', 'row_version' => 1])->assertUnprocessable();
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'diagnosing', 'row_version' => 1])->assertOk();
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'in_progress', 'row_version' => 1])->assertConflict();
        $ticket->refresh()->update(['customer_consent_status' => 'pending']);
        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/update-progress", ['status' => 'in_progress', 'row_version' => 2])->assertUnprocessable();
        $this->assertSame('diagnosing', $ticket->fresh()->status);
    }

    public function test_pelayan_cannot_choose_another_technician_when_creating_warranty_return(): void
    {
        $ticket = $this->ticket('delivered');
        $ticket->update(['delivered_at' => now(), 'warranty_expires_at' => now()->addDays(7)]);
        $payload = ['initial_complaint' => 'Layar kembali mati', 'same_symptom_confirmed' => true, 'warranty_reason' => 'Gejala sama dalam garansi'];
        $this->actingAs($this->employee('cs@toko.com'), 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/warranty-return", [...$payload, 'technician_employee_id' => $ticket->technician_employee_id])
            ->assertUnprocessable()->assertJsonValidationErrors('technician_employee_id');
        $this->actingAs($this->employee('cs@toko.com'), 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/warranty-return", $payload)
            ->assertCreated()->assertJsonPath('data.is_warranty_return', true);
        $this->assertDatabaseHas('service_tickets', ['warranty_returned_from_ticket_id' => $ticket->id, 'technician_employee_id' => $ticket->technician_employee_id]);
    }

    public function test_history_and_domain_calls_share_branch_and_assignment_scope(): void
    {
        $manager = $this->employee('manager@toko.com');
        $technician = $this->employee('teknisi@toko.com');
        $ticket = $this->ticket('delivered');
        $service = app(ServiceTicketService::class);
        // The domain is usable without an authenticated HTTP request.
        $this->assertTrue($service->scopeTickets($technician)->whereKey($ticket->id)->exists());
        $this->assertSame($ticket->id, $service->show($technician, [], (string) $ticket->id)['data']['id']);
        $ticket->update(['branch_id' => Branch::where('code', 'CAB-02')->firstOrFail()->id]);
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/operational/tickets/{$ticket->id}")->assertForbidden();
        $this->assertFalse($service->scopeTickets($manager)->whereKey($ticket->id)->exists());
    }

    public function test_cashier_estimate_invalidates_consent_and_pelayan_cannot_write_qc(): void
    {
        $ticket = $this->ticket('qc_ready');
        $cashier = $this->employee('kasir@toko.com');
        $cs = $this->employee('cs@toko.com');
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/estimated-cost", ['estimated_cost' => 100000, 'note' => 'Harga sparepart berubah', 'row_version' => 1])->assertOk()->assertJsonPath('data.customer_consent_status', 'pending');
        $this->actingAs($cs, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/complete", ['row_version' => 2])->assertForbidden();
        $this->actingAs($cs, 'sanctum')->getJson("/api/v1/operational/tickets/{$ticket->id}")
            ->assertOk()->assertJsonMissing(['available_actions' => ['complete']]);
        $this->assertNotContains('complete', app(ServiceTicketService::class)->availableTicketActions($ticket->fresh(), $cs));
    }

    public function test_pelayan_cannot_deliver_another_pelayans_ticket(): void
    {
        $ticket = $this->ticket('completed');
        $ticket->update(['intake_by_employee_id' => Employee::where('email', 'kasir@toko.com')->firstOrFail()->id]);
        $this->actingAs($this->employee('cs@toko.com'), 'sanctum')->postJson("/api/v1/operational/tickets/{$ticket->id}/deliver", [
            'row_version' => 1, 'recipient_type' => 'customer', 'recipient_name' => 'Pelanggan',
        ])->assertForbidden();
    }

    public function test_service_evidence_uses_ticket_scope_and_hides_storage_path(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket('delivered');
        $path = "quarantine/service-tickets/{$ticket->id}/evidence/qc.pdf";
        Storage::disk('local')->put($path, 'bukti QC');
        $ticket->update(['technical_evidence_json' => [['file_path' => $path, 'file_name' => 'qc.pdf', 'reference' => 'Hasil QC', 'scan_status' => 'quarantine']]]);
        $technician = $this->employee('teknisi@toko.com');
        $this->actingAs($technician, 'sanctum')->getJson("/api/v1/operational/tickets/{$ticket->id}")
            ->assertOk()->assertJsonMissingPath('data.technical_evidence.0.file_path');
        $this->actingAs($technician, 'sanctum')->get("/api/v1/operational/tickets/{$ticket->id}/evidence/0")->assertNotFound();
        $ticket->update(['technical_evidence_json' => [['file_path' => $path, 'file_name' => 'qc.pdf', 'reference' => 'Hasil QC', 'scan_status' => 'clean']]]);
        $this->actingAs($technician, 'sanctum')->get("/api/v1/operational/tickets/{$ticket->id}/evidence/0")->assertDownload('qc.pdf');
        $ticket->update(['branch_id' => Branch::where('code', 'CAB-02')->firstOrFail()->id]);
        $this->actingAs($technician, 'sanctum')->get("/api/v1/operational/tickets/{$ticket->id}/evidence/0")->assertNotFound();
    }
}
