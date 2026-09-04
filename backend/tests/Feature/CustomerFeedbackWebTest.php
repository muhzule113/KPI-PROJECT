<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFeedbackWebTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_cs_can_generate_a_signed_feedback_qr_for_a_completed_ticket(): void
    {
        $ticket = $this->makeCompletedTicket();
        $user = User::where('email', 'cs@toko.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/app/customer-feedback?ticket='.$ticket->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/CustomerFeedback')
            ->where('selected_ticket.id', $ticket->id)
            ->where('feedback_url', fn (string $url): bool => str_contains($url, '/customer-feedback/'.$ticket->id) && str_contains($url, 'signature='))
        );
    }

    public function test_non_cs_user_cannot_open_the_feedback_qr_manager(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user)->get('/app/customer-feedback')->assertForbidden();
    }

    public function test_customer_can_submit_feedback_from_the_signed_qr_url(): void
    {
        $ticket = $this->makeCompletedTicket();
        $admin = User::where('email', 'cs@toko.com')->firstOrFail();
        $qrUrl = $this->actingAs($admin)
            ->get('/app/customer-feedback?ticket='.$ticket->id)
            ->inertiaProps('feedback_url');

        $this->post($qrUrl, [
            'rating' => 4,
            'comments' => 'Pelayanannya cepat dan ramah.',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_feedbacks', [
            'service_ticket_id' => $ticket->id,
            'cs_employee_id' => $ticket->intake_by_employee_id,
            'rating' => 4,
            'feedback_channel' => 'qr_code',
        ]);
        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id,
            'status' => 'delivered',
        ]);
    }

    public function test_customer_cannot_tamper_with_the_ticket_in_a_feedback_qr_url(): void
    {
        $ticket = $this->makeCompletedTicket();
        $otherTicket = $this->makeCompletedTicket();
        $admin = User::where('email', 'cs@toko.com')->firstOrFail();
        $qrUrl = $this->actingAs($admin)
            ->get('/app/customer-feedback?ticket='.$ticket->id)
            ->inertiaProps('feedback_url');
        $tamperedUrl = str_replace('/customer-feedback/'.$ticket->id, '/customer-feedback/'.$otherTicket->id, $qrUrl);

        $this->get($tamperedUrl)->assertForbidden();
    }

    private function makeCompletedTicket(): ServiceTicket
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $cs = Employee::where('email', 'cs@toko.com')->firstOrFail();

        return ServiceTicket::create([
            'ticket_number' => 'SRV-QR-'.random_int(100000, 999999),
            'customer_name' => 'Pelanggan QR',
            'customer_phone' => '081234567890',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Layar rusak',
            'branch_id' => 1,
            'period_id' => $period->id,
            'intake_by_employee_id' => $cs->id,
            'status' => 'completed',
            'result_status' => 'success',
            'completed_at' => now(),
        ]);
    }
}
