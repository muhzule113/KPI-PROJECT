<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\User;
use App\Modules\Assessment\OperationalKpiSyncService;
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
            'end_date' => now()->addDay()->toDateString(),
        ]);

        // 1. Kasir membuat Nota Servis berdasarkan penerimaan Pelayan
        $createRes = $this->actingAs($userKasir, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'Budi Testing',
            'customer_phone' => '081234567899',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Baterai drop cepat panas',
            'estimated_cost' => 450000,
            'technician_employee_id' => $empTek->id,
            'pelayan_employee_id' => $empPelayan->id,
        ]);

        $createRes->assertStatus(201);
        $ticketId = $createRes->json('data.id');

        // 2. Technician advances the ticket through the mandatory QC gate
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'status' => 'diagnosing',
            ])
            ->assertStatus(200);
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'status' => 'in_progress',
            ])
            ->assertStatus(200);
        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'status' => 'qc_ready',
            ])
            ->assertStatus(200);

        // 3. Technician completes ticket with QC
        $completeRes = $this->actingAs($userTek, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticketId}/complete", [
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
            'final_cost' => 450000,
        ]);

        $completeRes->assertStatus(200);

        // 4. Pelayan menyerahkan unit dan mencatat feedback customer
        $feedbackRes = $this->actingAs($userPelayan, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticketId}/feedback", [
            'rating' => 5,
            'comments' => 'Pelayanan sangat memuaskan dan cepat!',
            'feedback_channel' => 'in_store',
            'follow_up_ontime' => true,
        ]);

        $feedbackRes->assertStatus(200);

        // 5. Verify Teknisi KPI is automatically populated from operational data
        $tekKpi = $empTek->kpis()->where('period_id', $period->id)->first();
        $this->assertNotNull($tekKpi);

        $tek01 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-01')->first();
        $this->assertNotNull($tek01);
        $this->assertGreaterThan(0, $tek01->actual_decimal); // Has real count of completed tickets

        $tek02 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-02')->first();
        $this->assertNotNull($tek02);
        $this->assertGreaterThanOrEqual(80.0, (float) $tek02->actual_decimal); // High success rate

        $tek07 = $tekKpi->items()->where('definition_code_snapshot', 'TEK-07')->first();
        $this->assertNotNull($tek07);
        $this->assertEquals(100.0, $tek07->actual_decimal); // Complete reports

        $this->assertDatabaseHas('audit_events', [
            'action' => 'api_ticket_completed',
            'subject_type' => 'ServiceTicket',
            'subject_id' => $ticketId,
        ]);
    }

    public function test_manager_or_admin_created_ticket_inherits_pelayan_branch(): void
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

        $response->assertCreated();
        $ticket = ServiceTicket::findOrFail($response->json('data.id'));
        $this->assertSame((string) $pelayan->branch_id, (string) $ticket->branch_id);
    }
}
