<?php

namespace Tests\Feature;

use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\FeedbackFollowUp;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CustomerFeedbackWebTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manager_can_generate_one_signed_progress_link_for_an_active_ticket(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_INTAKE);
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $webResponse = $this->actingAs($manager)->get('/app/customer-feedback?ticket='.$ticket->id);
        $webUrl = $webResponse->inertiaProps('feedback_url');

        $webResponse->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/CustomerFeedback')
            ->where('selected_ticket.id', $ticket->id)
            ->where('feedback_url', fn (string $url): bool => str_contains($url, '/customer-feedback/'.$ticket->id) && str_contains($url, 'signature='))
        );

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticket->id}/feedback-link")
            ->assertOk()
            ->assertJsonPath('data.url', $webUrl)
            ->assertJsonPath('data.expires_at', null);

        $this->get($webUrl)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_unsigned_or_tampered_progress_link_is_rejected(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_INTAKE);
        $otherTicket = $this->makeTicket(ServiceTicket::STATUS_INTAKE);
        $url = URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->id]);

        $this->get("/customer-feedback/{$ticket->id}")->assertForbidden();
        $this->get(str_replace("/customer-feedback/{$ticket->id}", "/customer-feedback/{$otherTicket->id}", $url))->assertForbidden();
        $this->post(str_replace("/customer-feedback/{$ticket->id}", "/customer-feedback/{$otherTicket->id}", $url), [
            'rating' => 5,
            'technician_rating' => 5,
        ])->assertForbidden();
    }

    public function test_public_progress_payload_is_safe_and_maps_every_status(): void
    {
        $labels = [
            ServiceTicket::STATUS_INTAKE => 'Diterima',
            ServiceTicket::STATUS_DIAGNOSING => 'Diagnosis',
            ServiceTicket::STATUS_WAITING_SPAREPART => 'Menunggu sparepart',
            ServiceTicket::STATUS_IN_PROGRESS => 'Dikerjakan',
            ServiceTicket::STATUS_QC_READY => 'Siap QC',
            ServiceTicket::STATUS_COMPLETED => 'Selesai',
            ServiceTicket::STATUS_DELIVERED => 'Diserahkan',
            ServiceTicket::STATUS_CANCELLED => 'Dibatalkan',
        ];

        foreach ($labels as $status => $label) {
            $ticket = $this->makeTicket($status);
            $response = $this->get(URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->id]));

            $response->assertOk()->assertInertia(fn ($page) => $page
                ->where('ticket.status', $status)
                ->where('ticket.status_label', $label)
                ->where('ticket.timeline', fn ($timeline): bool => $timeline->contains(
                    fn ($step): bool => $step['key'] === $status && $step['label'] === $label && $step['state'] === 'current'
                ))
                ->where('ticket', function ($payload): bool {
                    $forbidden = [
                        'customer_phone', 'customer_address', 'imei_or_serial', 'passcode_or_pattern',
                        'diagnosis_notes', 'action_notes', 'technical_evidence_json', 'estimated_cost',
                        'final_cost', 'payment_status', 'paid_amount',
                    ];

                    return $payload->keys()->intersect($forbidden)->isEmpty();
                })
            );

            if ($status === ServiceTicket::STATUS_DELIVERED) {
                $response->assertInertia(fn ($page) => $page
                    ->where('ticket.timeline.0.state', 'completed')
                    ->where('ticket.timeline.2.state', 'optional')
                    ->where('ticket.timeline.3.state', 'optional')
                    ->where('ticket.timeline.4.state', 'optional')
                    ->where('ticket.timeline.6.state', 'current')
                    ->missing('ticket.timeline.7')
                );
            }
            if ($status === ServiceTicket::STATUS_CANCELLED) {
                $response->assertInertia(fn ($page) => $page
                    ->where('ticket.timeline.0.state', 'completed')
                    ->where('ticket.timeline.1.key', ServiceTicket::STATUS_CANCELLED)
                    ->where('ticket.timeline.1.state', 'current')
                    ->missing('ticket.timeline.2')
                );
            }
        }
    }

    public function test_customer_can_submit_separate_ratings_after_delivery(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_DELIVERED);
        $url = URL::signedRoute('customer-feedback.store', ['ticket' => $ticket->id]);

        $this->post($url, [
            'rating' => 4,
            'technician_rating' => 5,
            'comments' => 'Pelayanannya cepat dan ramah.',
            'cs_employee_id' => Employee::where('email', 'teknisi@toko.com')->firstOrFail()->id,
            'technician_employee_id' => Employee::where('email', 'cs@toko.com')->firstOrFail()->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_feedbacks', [
            'service_ticket_id' => $ticket->id,
            'cs_employee_id' => $ticket->intake_by_employee_id,
            'technician_employee_id' => $ticket->technician_employee_id,
            'rating' => 4,
            'technician_rating' => 5,
            'feedback_channel' => 'qr_code',
        ]);
        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id, 'status' => ServiceTicket::STATUS_DELIVERED]);
    }

    public function test_feedback_requires_delivery_and_respects_the_seven_day_window(): void
    {
        $active = $this->makeTicket(ServiceTicket::STATUS_IN_PROGRESS);
        $this->post(URL::signedRoute('customer-feedback.store', ['ticket' => $active->id]), [
            'rating' => 5,
            'technician_rating' => 5,
        ])->assertUnprocessable();

        $expired = $this->makeTicket(ServiceTicket::STATUS_DELIVERED, now()->subDays(8));
        $url = URL::signedRoute('customer-feedback.show', ['ticket' => $expired->id]);
        $this->get($url)->assertNotFound();
        $this->post(URL::signedRoute('customer-feedback.store', ['ticket' => $expired->id]), [
            'rating' => 5,
            'technician_rating' => 5,
        ])->assertUnprocessable();

        $cancelled = $this->makeTicket(ServiceTicket::STATUS_CANCELLED, now()->subDays(8));
        $cancelled->update(['cancellation_reason' => 'Catatan setelah pembatalan']);
        $this->get(URL::signedRoute('customer-feedback.show', ['ticket' => $cancelled->id]))->assertNotFound();
    }

    public function test_ticket_without_technician_does_not_accept_a_technician_rating(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_DELIVERED, now(), false);
        $url = URL::signedRoute('customer-feedback.store', ['ticket' => $ticket->id]);

        $this->post($url, ['rating' => 5, 'technician_rating' => 4])->assertSessionHasErrors('technician_rating');
        $this->post($url, ['rating' => 5])->assertRedirect();

        $this->assertDatabaseHas('customer_feedbacks', [
            'service_ticket_id' => $ticket->id,
            'technician_employee_id' => null,
            'technician_rating' => null,
        ]);
    }

    public function test_second_submission_is_rejected_without_creating_a_duplicate(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_DELIVERED);
        $url = URL::signedRoute('customer-feedback.store', ['ticket' => $ticket->id]);
        $payload = ['rating' => 5, 'technician_rating' => 5];

        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertConflict();

        $this->assertSame(1, CustomerFeedback::where('service_ticket_id', $ticket->id)->count());
    }

    public function test_low_rating_for_either_employee_creates_exactly_one_follow_up_for_the_pelayan(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_DELIVERED);

        $this->post(URL::signedRoute('customer-feedback.store', ['ticket' => $ticket->id]), [
            'rating' => 5,
            'technician_rating' => 1,
            'comments' => 'Perlu ditindaklanjuti.',
        ])->assertRedirect();

        $followUps = FeedbackFollowUp::where('service_ticket_id', $ticket->id)->get();
        $this->assertCount(1, $followUps);
        $this->assertSame($ticket->intake_by_employee_id, $followUps->first()->assigned_employee_id);
        $this->assertSame(FeedbackFollowUp::STATUS_PENDING, $followUps->first()->status);
        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id, 'status' => ServiceTicket::STATUS_DELIVERED]);

        $cs01 = EmployeeKpi::query()
            ->where('employee_id', $ticket->intake_by_employee_id)
            ->where('period_id', $ticket->period_id)
            ->firstOrFail()
            ->items()
            ->where('definition_code_snapshot', 'CS-01')
            ->firstOrFail();
        $this->assertSame(100.0, (float) $cs01->actual_decimal);
    }

    public function test_feedback_api_keeps_pelayan_aliases_and_adds_technician_data(): void
    {
        CustomerFeedback::query()->delete();
        $ticket = $this->makeTicket(ServiceTicket::STATUS_DELIVERED);
        CustomerFeedback::create([
            'service_ticket_id' => $ticket->id,
            'cs_employee_id' => $ticket->intake_by_employee_id,
            'technician_employee_id' => $ticket->technician_employee_id,
            'customer_name' => $ticket->customer_name,
            'rating' => 4,
            'technician_rating' => 2,
        ]);
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/operational/feedback')
            ->assertOk()
            ->assertJsonPath('data.stats.average', 4)
            ->assertJsonPath('data.stats.pelayan_average', 4)
            ->assertJsonPath('data.stats.technician_average', 2)
            ->assertJsonPath('data.feedbacks.0.rating', 4)
            ->assertJsonPath('data.feedbacks.0.employee', $ticket->intakeEmployee->name)
            ->assertJsonPath('data.feedbacks.0.technician_rating', 2)
            ->assertJsonPath('data.feedbacks.0.technician_employee', $ticket->technicianEmployee->name);
    }

    public function test_final_link_reports_business_expiry_but_keeps_the_same_url(): void
    {
        $ticket = $this->makeTicket(ServiceTicket::STATUS_IN_PROGRESS);
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $active = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticket->id}/feedback-link")
            ->json('data');

        $ticket->update([
            'status' => ServiceTicket::STATUS_DELIVERED,
            'completed_at' => now(),
            'delivered_at' => now(),
        ]);
        $final = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticket->id}/feedback-link")
            ->assertOk()
            ->json('data');

        $this->assertSame($active['url'], $final['url']);
        $this->assertNotNull($final['expires_at']);
    }

    public function test_non_cs_user_cannot_open_the_progress_link_manager(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user)->get('/app/customer-feedback')->assertRedirect('/login');
        $ticket = $this->makeTicket(ServiceTicket::STATUS_INTAKE);
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticket->id}/feedback-link")
            ->assertForbidden();
    }

    private function makeTicket(string $status, mixed $terminalAt = null, bool $withTechnician = true): ServiceTicket
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $cs = Employee::where('email', 'cs@toko.com')->firstOrFail();
        $technician = $withTechnician ? Employee::where('email', 'teknisi@toko.com')->firstOrFail() : null;
        $terminalAt ??= now();

        $ticket = ServiceTicket::create([
            'ticket_number' => 'SRV-QR-'.random_int(100000, 999999),
            'customer_name' => 'Pelanggan QR',
            'customer_phone' => '081234567890',
            'customer_address' => 'Data privat',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'imei_or_serial' => 'SECRET-IMEI',
            'passcode_or_pattern' => '1234',
            'initial_complaint' => 'Layar rusak',
            'branch_id' => 1,
            'period_id' => $period->id,
            'intake_by_employee_id' => $cs->id,
            'technician_employee_id' => $technician?->id,
            'status' => $status,
            'result_status' => in_array($status, [ServiceTicket::STATUS_COMPLETED, ServiceTicket::STATUS_DELIVERED], true) ? 'success' : 'pending',
            'completed_at' => in_array($status, [ServiceTicket::STATUS_COMPLETED, ServiceTicket::STATUS_DELIVERED], true) ? $terminalAt : null,
            'delivered_at' => $status === ServiceTicket::STATUS_DELIVERED ? $terminalAt : null,
            'estimated_completion_at' => now()->addDay(),
            'diagnosis_notes' => 'Catatan privat',
            'action_notes' => 'Tindakan privat',
            'final_cost' => 100000,
            'payment_status' => 'paid',
        ]);

        if ($status === ServiceTicket::STATUS_CANCELLED && $terminalAt) {
            DB::table('service_tickets')->where('id', $ticket->id)->update([
                'cancelled_at' => $terminalAt,
                'updated_at' => $terminalAt,
            ]);
            $ticket->refresh();
        }

        return $ticket->load(['intakeEmployee', 'technicianEmployee']);
    }
}
