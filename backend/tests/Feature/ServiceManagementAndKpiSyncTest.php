<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceManagementAndKpiSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_operational_service_tickets_auto_sync_to_kpi(): void
    {
        $userKasir = User::where('email', 'kasir@toko.com')->first();
        $userPelayan = User::where('email', 'cs@toko.com')->first();
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $empPelayan = Employee::where('email', 'cs@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($period);
        $this->assertNotNull($empTek);
        $this->assertNotNull($empPelayan);
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        // 1. Pelayan membuat Tiket Servis resmi tanpa assignment Teknisi
        $createRes = $this->actingAs($userPelayan, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'Budi Testing',
            'customer_phone' => '081234567899',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Baterai drop cepat panas',
            'pelayan_employee_id' => $empPelayan->id,
        ]);

        $createRes->assertStatus(201);
        $ticketId = $createRes->json('data.id');

        // 3. Pelayan mencatat persetujuan customer, lalu Teknisi menyelesaikan ticket dengan QC
        $this->actingAs($userPelayan, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/consent", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'consent_status' => 'approved',
                'consent_notes' => 'Customer menyetujui tindakan servis.',
            ])
            ->assertOk();
        // 2. Teknisi claim lalu advances the ticket through the mandatory QC gate
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/assign", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version, 'row_version' => ServiceTicket::findOrFail($ticketId)->row_version])
            ->assertOk();
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'diagnosing',
            ])
            ->assertStatus(200);
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'in_progress',
            ])
            ->assertStatus(200);
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'qc_ready',
            ])
            ->assertStatus(200);

        $completeRes = $this->actingAs($userTek, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticketId}/complete", [
            'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
            'result_status' => 'success',
            'diagnosis_notes' => 'Baterai health 68%',
            'action_notes' => 'Ganti baterai baru original dan kalibrasi BMS',
            'qc_checklist' => [
                'display' => true,
                'touch' => true,
                'camera' => true,
                'mic' => true,
                'speaker' => true,
                'cellular' => true,
                'charging' => true,
                'biometric' => true,
            ],
            'technical_evidence' => [
                ['type' => 'service_note', 'reference' => 'QC-API-001'],
            ],
        ]);

        $completeRes->assertStatus(200);

        // 4. Kasir mencatat biaya dan pembayaran, lalu Pelayan menyerahkan unit
        $this->actingAs($userKasir, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/final-cost", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'final_cost' => 450000,
                'note' => 'Biaya final dikonfirmasi setelah inspeksi teknis.',
            ])
            ->assertOk();
        $this->actingAs($userKasir, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/payment", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'paid_amount' => 450000,
            ])
            ->assertOk();
        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticketId,
            'cashier_employee_id' => $userKasir->employee->id,
            'final_cost' => 450000,
            'paid_amount' => 450000,
        ]);
        $feedbackRes = $this->actingAs($userPelayan, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticketId}/deliver", [
            'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
            'recipient_type' => 'customer',
            'recipient_name' => 'Budi Testing',
        ]);

        $feedbackRes->assertStatus(200)->assertJsonPath('data.status', 'delivered');

        // 5. Verify Teknisi KPI is automatically populated from operational data
        $tekKpi = $empTek->kpis()->where('period_id', $period->id)->first();
        $this->assertNotNull($tekKpi);

        $tek01 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-01')->first();
        $this->assertNotNull($tek01);
        $this->assertGreaterThan(0, $tek01->actual_decimal); // Has real count of completed tickets

        $tek02 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-02')->first();
        $this->assertNotNull($tek02);
        $completedTickets = ServiceTicket::where('technician_employee_id', $empTek->id)
            ->whereBetween('completed_at', [$period->start_date->startOfDay(), $period->end_date->endOfDay()])
            ->whereIn('status', ['completed', 'delivered'])
            ->get();
        $this->assertEquals(
            round(($completedTickets->where('result_status', 'success')->count() / $completedTickets->count()) * 100, 2),
            (float) $tek02->actual_decimal,
        );

        $tek07 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-07')->first();
        $this->assertNotNull($tek07);
        $this->assertEquals(100.0, $tek07->actual_decimal); // Complete reports

        $this->assertDatabaseHas('audit_events', [
            'action' => 'api_ticket_completed',
            'subject_type' => 'ServiceTicket',
            'subject_id' => $ticketId,
        ]);
    }

    public function test_system_administrator_cannot_create_operational_ticket(): void
    {
        $manager = User::where('email', 'admin@kpi.com')->firstOrFail();
        $pelayan = Employee::where('email', 'cs@toko.com')->firstOrFail();

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'Branch Inheritance Test',
            'customer_phone' => '081234567800',
            'device_brand' => 'Samsung',
            'device_model' => 'Galaxy A54',
            'initial_complaint' => 'Layar tidak menyala',
            'pelayan_employee_id' => $pelayan->id,
        ]);

        $response->assertForbidden();
    }

    public function test_pelayan_cannot_set_estimated_cost_when_creating_ticket(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();

        $this->actingAs($pelayan, 'sanctum')
            ->postJson('/api/v1/operational/tickets', [
                'customer_name' => 'Pelanggan Tanpa Biaya Pelayan',
                'customer_phone' => '081234567890',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54',
                'initial_complaint' => 'Layar retak',
                'estimated_cost' => 350000,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Estimasi biaya hanya dapat diisi oleh Kasir atau manajemen.');

        $this->assertDatabaseMissing('service_tickets', [
            'customer_name' => 'Pelanggan Tanpa Biaya Pelayan',
        ]);
    }

    public function test_kasir_can_set_estimated_cost_on_pelayan_ticket(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $kasir = User::where('email', 'kasir@toko.com')->firstOrFail();
        $ticket = ServiceTicket::create([
            'ticket_number' => 'SRV-CASHIER-ESTIMATE',
            'customer_name' => 'Pelanggan Kasir',
            'customer_phone' => '081234567890',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Baterai cepat habis',
            'branch_id' => $pelayan->employee->branch_id,
            'period_id' => KpiPeriod::where('status', 'OPEN')->firstOrFail()->id,
            'intake_by_employee_id' => $pelayan->employee->id,
            'status' => ServiceTicket::STATUS_INTAKE,
            'result_status' => ServiceTicket::RESULT_PENDING,
        ]);

        $this->actingAs($kasir, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/estimated-cost", [
                'estimated_cost' => 350000,
                'note' => 'Estimasi Nota Servis.',
                'row_version' => $ticket->row_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.estimated_cost', 350000)
            ->assertJsonPath('data.cashier_name', $kasir->employee->name);

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id,
            'estimated_cost' => 350000,
            'cashier_employee_id' => $kasir->employee->id,
        ]);
    }
}
