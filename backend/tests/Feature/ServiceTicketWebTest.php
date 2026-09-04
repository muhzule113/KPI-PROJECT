<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Branch;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Modules\Assessment\OperationalKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTicketWebTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_pelayan_can_open_web_form_and_create_intake_ticket(): void
    {
        $user = User::where('email', 'cs@toko.com')->firstOrFail();
        $pelayan = Employee::where('email', 'cs@toko.com')->firstOrFail();
        $technician = Employee::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/app/service-tickets/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ResourceForm')
                ->where('resource.key', 'service-tickets')
                ->where('resource.can_create', true)
            );

        $this->actingAs($user)
            ->post('/app/service-tickets', [
                'customer_name' => 'Pelanggan Web',
                'customer_phone' => '081234567890',
                'intake_by_employee_id' => $pelayan->id,
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54',
                'imei_or_serial' => 'IMEI-WEB-001',
                'initial_complaint' => 'Baterai cepat habis',
                'customer_needs' => 'Selesai sebelum akhir pekan.',
                'physical_condition' => 'Ada lecet ringan di sudut kanan.',
                'technician_employee_id' => $technician->id,
                'status' => 'delivered',
                'result_status' => 'success',
                'estimated_cost' => 350000,
                'final_cost' => 350000,
            ])
            ->assertRedirect('/app/service-tickets');

        $this->assertDatabaseHas('service_tickets', [
            'customer_name' => 'Pelanggan Web',
            'intake_by_employee_id' => $pelayan->id,
            'technician_employee_id' => $technician->id,
            'status' => 'intake',
            'result_status' => 'pending',
            'final_cost' => 0,
            'customer_needs' => 'Selesai sebelum akhir pekan.',
        ]);
    }

    public function test_web_ticket_completion_syncs_to_the_technician_kpi(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $pelayan = Employee::where('email', 'cs@toko.com')->firstOrFail();
        $technician = Employee::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        $ticket = ServiceTicket::create([
            'ticket_number' => 'SRV-WEB-SYNC-001',
            'customer_name' => 'Pelanggan Sinkron Web',
            'customer_phone' => '081234567891',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Baterai cepat habis',
            'estimated_cost' => 300000,
            'final_cost' => 300000,
            'estimated_completion_at' => now()->addHour(),
            'branch_id' => $technician->branch_id,
            'period_id' => $period->id,
            'intake_by_employee_id' => $pelayan->id,
            'technician_employee_id' => $technician->id,
            'status' => 'qc_ready',
            'result_status' => 'pending',
            'diagnosis_notes' => 'Baterai rusak.',
            'action_notes' => 'Ganti baterai.',
            'qc_checklist_json' => [
                'display' => true,
                'touch' => true,
                'camera' => true,
                'mic' => true,
                'speaker' => true,
                'cellular' => true,
                'charging' => true,
                'biometric' => true,
            ],
            'started_at' => now()->subHour(),
        ]);

        app(OperationalKpiSyncService::class)->syncPeriodOperationalData($period);
        $kpi = $technician->kpis()->where('period_id', $period->id)->firstOrFail();
        $before = (float) $kpi->items()->where('definition_code_snapshot', 'TEK-01')->value('actual_decimal');

        $this->actingAs($manager)
            ->put("/app/service-tickets/{$ticket->id}", [
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'customer_phone' => $ticket->customer_phone,
                'intake_by_employee_id' => $pelayan->id,
                'device_brand' => $ticket->device_brand,
                'device_model' => $ticket->device_model,
                'initial_complaint' => $ticket->initial_complaint,
                'technician_employee_id' => $technician->id,
                'status' => 'completed',
                'result_status' => 'success',
                'estimated_cost' => 300000,
                'final_cost' => 300000,
                'estimated_completion_at' => now()->addHour()->format('Y-m-d\\TH:i'),
                'diagnosis_notes' => $ticket->diagnosis_notes,
                'action_notes' => $ticket->action_notes,
            ])
            ->assertRedirect('/app/service-tickets');

        $this->assertNotNull($ticket->fresh()->completed_at);
        $this->assertSame(
            $before + 1,
            (float) $kpi->items()->where('definition_code_snapshot', 'TEK-01')->value('actual_decimal')
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'web_resource_updated',
            'subject_type' => 'ServiceTicket',
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_cashier_cannot_delete_service_ticket_from_another_branch(): void
    {
        $cashierUser = User::where('email', 'kasir@toko.com')->firstOrFail();
        $cashier = Employee::where('email', 'kasir@toko.com')->firstOrFail();
        $ownBranch = Branch::where('code', 'CAB-01')->firstOrFail();
        $otherBranch = Branch::where('code', 'CAB-02')->firstOrFail();

        $cashier->update(['branch_id' => $otherBranch->id]);

        $ticket = ServiceTicket::create([
            'ticket_number' => 'SRV-WEB-IDOR-001',
            'customer_name' => 'Pelanggan Cabang Lain',
            'customer_phone' => '081234567892',
            'device_brand' => 'Xiaomi',
            'device_model' => 'Redmi Note',
            'initial_complaint' => 'Layar bermasalah',
            'branch_id' => $ownBranch->id,
            'status' => 'intake',
            'result_status' => 'pending',
        ]);

        $this->actingAs($cashierUser)
            ->delete("/app/service-tickets/{$ticket->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id,
            'branch_id' => $ownBranch->id,
        ]);
    }

    public function test_technician_cannot_run_global_service_ticket_sync_action(): void
    {
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();

        $this->actingAs($technician)
            ->post('/app/service-tickets/actions/sync_kpi')
            ->assertForbidden();

        $this->actingAs($cashier)
            ->post('/app/service-tickets/actions/sync_kpi')
            ->assertForbidden();
    }
}
