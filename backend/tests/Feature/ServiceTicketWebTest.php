<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTicketWebTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function ticket(string $status = 'intake'): ServiceTicket
    {
        $cs = User::where('email', 'cs@toko.com')->firstOrFail()->employee;

        return ServiceTicket::create([
            'ticket_number' => 'SRV-WEB-'.str()->uuid(),
            'customer_name' => 'Pelanggan web', 'customer_phone' => '081234567899',
            'device_brand' => 'Samsung', 'device_model' => 'A54', 'initial_complaint' => 'Layar pecah',
            'branch_id' => $cs->branch_id, 'intake_by_employee_id' => $cs->id, 'status' => $status,
        ]);
    }

    public function test_mobile_only_roles_cannot_use_web_ticket_urls(): void
    {
        $ticket = $this->ticket();
        foreach (['cs@toko.com', 'teknisi@toko.com', 'gudang@toko.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)->get('/app/service-tickets')->assertRedirect('/login');
            $this->actingAs($user)->get("/app/service-tickets/{$ticket->id}/workflow")->assertRedirect('/login');
        }
    }

    public function test_manager_cannot_create_or_edit_technical_records(): void
    {
        $ticket = $this->ticket('qc_ready');
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $this->actingAs($manager)->post('/app/service-tickets', [])->assertForbidden();
        $this->actingAs($manager)->put("/app/service-tickets/{$ticket->id}", ['status' => 'completed'])->assertForbidden();
        $this->actingAs($manager)->get("/app/service-tickets/{$ticket->id}/complete")->assertForbidden();
    }

    public function test_super_admin_can_manage_technical_records_across_branches(): void
    {
        $admin = User::where('email', 'admin@kpi.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail()->employee;
        $ticket = $this->ticket('in_progress');
        $ticket->update([
            'technician_employee_id' => $technician->id,
            'customer_consent_status' => 'approved',
        ]);

        $this->actingAs($admin)->put("/app/service-tickets/{$ticket->id}/status", [
            'status' => 'qc_ready',
            'row_version' => $ticket->row_version,
        ])->assertRedirect('/app/service-tickets');

        $this->assertSame('qc_ready', $ticket->fresh()->status);
        $this->actingAs($admin)->get("/app/service-tickets/{$ticket->id}/complete")->assertOk();
    }

    public function test_cashier_records_cost_through_shared_domain_and_rejects_stale_form(): void
    {
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $ticket = $this->ticket();
        $this->actingAs($cashier)->get("/app/service-tickets/{$ticket->id}/cost")->assertOk();
        $payload = ['estimated_cost' => 150000, 'note' => 'Harga setelah pemeriksaan', 'row_version' => 1];
        $this->actingAs($cashier)->post("/app/service-tickets/{$ticket->id}/cost", $payload)->assertRedirect('/app/service-tickets');
        $this->assertEquals(150000, $ticket->fresh()->estimated_cost);
        $this->assertSame($cashier->employee->id, $ticket->fresh()->cashier_employee_id);
        $this->actingAs($cashier)->from("/app/service-tickets/{$ticket->id}/cost")
            ->post("/app/service-tickets/{$ticket->id}/cost", $payload)->assertSessionHasErrors('cost');
    }

    public function test_assigned_manager_can_assign_but_cannot_skip_technical_work(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail()->employee;
        $ticket = $this->ticket();
        $this->actingAs($manager)->get("/app/service-tickets/{$ticket->id}/workflow")->assertOk();
        $this->actingAs($manager)->post("/app/service-tickets/{$ticket->id}/workflow", [
            'action' => 'assign', 'technician_employee_id' => $technician->id,
            'assignment_reason' => 'Penugasan antrean cabang', 'row_version' => 1,
        ])->assertRedirect();
        $this->assertSame($technician->id, $ticket->fresh()->technician_employee_id);
        $this->actingAs($manager)->put("/app/service-tickets/{$ticket->id}/status", ['status' => 'qc_ready', 'row_version' => 2])->assertForbidden();
    }

    public function test_web_workflow_rejects_other_branch_even_for_manager(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $ticket = $this->ticket();
        $ticket->update(['branch_id' => Branch::where('code', 'CAB-02')->firstOrFail()->id]);
        $this->actingAs($manager)->get("/app/service-tickets/{$ticket->id}/workflow")->assertForbidden();
    }
}
