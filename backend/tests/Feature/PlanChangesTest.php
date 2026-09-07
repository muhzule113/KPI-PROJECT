<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanChangesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_technician_can_login_for_mobile_parity(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'teknisi@toko.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.employee.position_code', 'POS-TEK');
        $this->assertContains('tickets.claim', $response->json('data.capabilities'));
    }

    public function test_manager_can_change_staff_manual_predicate_with_reason(): void
    {
        [, $date] = $this->openPeriodForToday();
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $service = app(DailyAssessmentService::class);

        $day = $service->employeeDay($employee, $date);
        $item = $day['kpi']->items()->where('definition_code_snapshot', 'TEK-05')->firstOrFail();
        $entry = KpiDailyEntry::where('employee_kpi_item_id', $item->id)
            ->whereDate('entry_date', $date)
            ->firstOrFail();

        $service->assessSupervisor(
            $supervisor,
            $entry->id,
            'approved',
            actualJson: ['rating_code' => 'GOOD'],
            note: 'Perlu peningkatan konsistensi SOP.',
        );
        try {
            $service->assessManager($manager, $entry->id, 'approved', actualJson: ['rating_code' => 'VERY_GOOD']);
            $this->fail('Perubahan Manager tanpa alasan harus ditolak.');
        } catch (\Exception $exception) {
            $this->assertSame('Perubahan penilaian Manager wajib menyertakan alasan.', $exception->getMessage());
        }
        $this->assertSame(85.0, (float) $entry->fresh()->supervisor_score_percentage);
        $this->assertNull($entry->fresh()->manager_score_percentage);
        $this->assertSame('pending', $entry->fresh()->manager_status);

        $service->assessManager(
            $manager,
            $entry->id,
            'approved',
            actualJson: ['rating_code' => 'VERY_GOOD'],
            note: 'Observasi Manager menunjukkan hasil lebih konsisten.',
        );

        $this->assertSame(95.0, (float) $entry->fresh()->effectiveRubricScore());
        $this->assertSame('approved', $entry->fresh()->manager_status);
        $this->assertSame(95.0, (float) $item->fresh()->actual_decimal);
    }

    public function test_attendance_is_recorded_from_daily_review(): void
    {
        [, $date] = $this->openPeriodForToday();
        $employee = User::where('email', 'cs@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $service = app(DailyAssessmentService::class);

        $day = $service->employeeDay($employee, $date);
        $item = $day['kpi']->items()->where('definition_code_snapshot', 'CS-06')->firstOrFail();
        $entry = KpiDailyEntry::where('employee_kpi_item_id', $item->id)
            ->whereDate('entry_date', $date)
            ->firstOrFail();

        $reviewed = $service->assessSupervisor(
            $supervisor,
            $entry->id,
            'approved',
            actualJson: ['attendance_status' => Attendance::STATUS_PRESENT],
        );

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->employee->id,
            'attendance_date' => $date,
            'status' => Attendance::STATUS_PRESENT,
            'recorded_by' => $supervisor->id,
        ]);
        $this->assertSame(100.0, (float) $reviewed->supervisor_actual_decimal);

        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $managerReview = $service->assessManager(
            $manager,
            $entry->id,
            'approved',
            actualJson: ['attendance_status' => Attendance::STATUS_ABSENT],
            note: 'Manager menemukan ketidaksesuaian pada hasil penilaian.',
        );

        $this->assertSame(0.0, (float) $managerReview->effectiveActualDecimal());
        $this->assertSame(0.0, (float) $item->fresh()->actual_decimal);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->employee->id,
            'attendance_date' => $date,
            'status' => Attendance::STATUS_PRESENT,
            'recorded_by' => $supervisor->id,
        ]);
    }

    public function test_pelayan_cannot_update_unassigned_ticket_progress(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();

        $created = $this->actingAs($pelayan, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'CS Workflow Test',
            'customer_phone' => '081234567890',
            'device_brand' => 'Samsung',
            'device_model' => 'A55',
            'initial_complaint' => 'Tidak bisa mengisi daya.',
        ])->assertCreated();

        $ticketId = $created->json('data.id');
        $rowVersion = $created->json('data.row_version');

        $this->actingAs($pelayan, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$created->json('data.id')}/update-progress", [
                'status' => 'diagnosing',
                'diagnosis_notes' => 'Port charging kotor dan konektor longgar.',
                'action_notes' => 'Pembersihan port dan pemeriksaan jalur charging.',
                'row_version' => $rowVersion,
            ])->assertForbidden();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticketId,
            'technician_employee_id' => null,
            'status' => ServiceTicket::STATUS_INTAKE,
            'diagnosis_notes' => null,
            'action_notes' => null,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'api_ticket_progress_updated',
            'subject_type' => 'ServiceTicket',
            'subject_id' => $ticketId,
            'actor_id' => $pelayan->id,
        ]);
    }

    public function test_pelayan_cannot_complete_qc_ready_ticket_without_technician(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $ticket = $this->makeQcReadyTicketAsPelayan('SRV-COMPLETE-PEL-001');

        $this->actingAs($pelayan, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/complete", $this->completionPayload($ticket))
            ->assertForbidden();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id,
            'technician_employee_id' => null,
            'status' => ServiceTicket::STATUS_QC_READY,
            'result_status' => ServiceTicket::RESULT_PENDING,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'api_ticket_completed',
            'subject_type' => 'ServiceTicket',
            'subject_id' => $ticket->id,
            'actor_id' => $pelayan->id,
        ]);
    }

    public function test_technician_completion_rejects_failed_qc_for_success_result(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $ticket = $this->makeQcReadyTicketAsPelayan('SRV-COMPLETE-PEL-002');
        $ticket->update(['technician_employee_id' => $technician->employee->id]);
        $payload = $this->completionPayload($ticket);
        $payload['qc_checklist']['charging'] = false;

        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/complete", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Servis sukses hanya dapat diselesaikan jika seluruh checklist QC lulus.');

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id,
            'status' => ServiceTicket::STATUS_QC_READY,
            'completed_at' => null,
        ]);
    }

    public function test_technician_cannot_update_ticket_from_another_branch(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        [$ticketId] = $this->createTicketAsPelayan($pelayan);
        ServiceTicket::whereKey($ticketId)->update(['technician_employee_id' => $technician->employee->id]);

        ServiceTicket::whereKey($ticketId)->update([
            'branch_id' => Branch::where('code', 'CAB-02')->firstOrFail()->id,
        ]);

        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'diagnosing',
            ])
            ->assertForbidden();
    }

    public function test_technician_cannot_update_final_ticket(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        [$ticketId] = $this->createTicketAsPelayan($pelayan);
        ServiceTicket::whereKey($ticketId)->update(['technician_employee_id' => $technician->employee->id]);

        ServiceTicket::whereKey($ticketId)->update(['status' => ServiceTicket::STATUS_DELIVERED]);

        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'diagnosing',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticketId,
            'status' => ServiceTicket::STATUS_DELIVERED,
        ]);
    }

    public function test_technician_update_rejects_stale_row_version(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        [$ticketId, $rowVersion] = $this->createTicketAsPelayan($pelayan);
        ServiceTicket::whereKey($ticketId)->update(['technician_employee_id' => $technician->employee->id]);

        ServiceTicket::whereKey($ticketId)->increment('row_version');

        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'status' => 'diagnosing',
                'row_version' => $rowVersion,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticketId,
            'status' => ServiceTicket::STATUS_INTAKE,
        ]);
    }

    public function test_technician_cannot_skip_ticket_status(): void
    {
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();
        [$ticketId] = $this->createTicketAsPelayan($pelayan);
        ServiceTicket::whereKey($ticketId)->update(['technician_employee_id' => $technician->employee->id]);

        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketId}/update-progress", [
                'row_version' => ServiceTicket::findOrFail($ticketId)->row_version,
                'status' => 'in_progress',
            ])
            ->assertUnprocessable();
    }

    private function createTicketAsPelayan(User $pelayan): array
    {
        $response = $this->actingAs($pelayan, 'sanctum')->postJson('/api/v1/operational/tickets', [
            'customer_name' => 'CS Status Test',
            'customer_phone' => '081234567890',
            'device_brand' => 'Samsung',
            'device_model' => 'A55',
            'initial_complaint' => 'Tidak bisa mengisi daya.',
        ])->assertCreated();

        return [$response->json('data.id'), $response->json('data.row_version')];
    }

    private function makeQcReadyTicketAsPelayan(string $ticketNumber): ServiceTicket
    {
        $pelayan = Employee::where('email', 'cs@toko.com')->firstOrFail();

        return ServiceTicket::create([
            'ticket_number' => $ticketNumber,
            'customer_name' => 'Pelanggan Selesai QC',
            'customer_phone' => '081234567890',
            'device_brand' => 'Samsung',
            'device_model' => 'A55',
            'initial_complaint' => 'Tidak dapat mengisi daya.',
            'branch_id' => $pelayan->branch_id,
            'period_id' => KpiPeriod::where('status', 'OPEN')->firstOrFail()->id,
            'intake_by_employee_id' => $pelayan->id,
            'technician_employee_id' => null,
            'status' => ServiceTicket::STATUS_QC_READY,
            'row_version' => 1,
            'result_status' => ServiceTicket::RESULT_PENDING,
            'customer_consent_status' => 'approved',
            'customer_consent_at' => now(),
        ]);
    }

    private function completionPayload(ServiceTicket $ticket): array
    {
        return [
            'result_status' => ServiceTicket::RESULT_SUCCESS,
            'diagnosis_notes' => 'Port charging dan jalur tegangan sudah diperiksa.',
            'action_notes' => 'Pembersihan port, perbaikan jalur, dan pengujian fungsi.',
            'qc_checklist' => array_fill_keys(ServiceTicket::REQUIRED_QC_KEYS, true),
            'technical_evidence' => [
                ['type' => 'service_note', 'reference' => 'QC-WEB-001'],
            ],
            'row_version' => $ticket->row_version,
        ];
    }

    private function openPeriodForToday(): array
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        return [$period->fresh(), now()->toDateString()];
    }
}
