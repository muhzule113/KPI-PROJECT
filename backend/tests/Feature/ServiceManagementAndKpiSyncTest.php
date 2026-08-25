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
        $userCs = User::where('email', 'cs@toko.com')->first();
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $empCs = Employee::where('email', 'cs@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($period);
        $this->assertNotNull($empTek);
        $this->assertNotNull($empCs);

        // 1. CS creates a new ticket via API
        $createRes = $this->actingAs($userCs, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'Budi Testing',
            'customer_phone' => '081234567899',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Baterai drop cepat panas',
            'estimated_cost' => 450000,
            'technician_employee_id' => $empTek->id,
        ]);

        $createRes->assertStatus(201);
        $ticketId = $createRes->json('data.id');

        // 2. Technician updates progress and completes ticket with QC
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
                'face_id' => true,
            ],
            'final_cost' => 450000,
        ]);

        $completeRes->assertStatus(200);

        // 3. CS marks delivered and records 5-star feedback
        $feedbackRes = $this->actingAs($userCs, 'sanctum')->postJson("/api/v1/operational/tickets/{$ticketId}/feedback", [
            'rating' => 5,
            'comments' => 'Pelayanan sangat memuaskan dan cepat!',
            'feedback_channel' => 'in_store',
        ]);

        $feedbackRes->assertStatus(200);

        // 4. Verify Teknisi KPI is automatically populated from operational data
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
    }
}
