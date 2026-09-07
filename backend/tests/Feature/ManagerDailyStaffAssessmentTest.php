<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerDailyStaffAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manager_can_review_and_approve_all_supervisor_assessed_staff_entries(): void
    {
        [$manager, $kpi, $date, $entries] = $this->approvedStaffEntries();

        $queue = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/manager/daily?date={$date}")
            ->assertOk()
            ->assertJsonPath('data.0.review_mode', 'staff_confirmation');
        $reviewable = KpiDailyEntry::whereIn('id', $entries->pluck('id'))->where('supervisor_status', 'approved')->get();
        $this->assertCount($reviewable->count(), collect($queue->json('data'))->where('kpi_id', $kpi->id));

        $beforeNotifications = SystemNotification::count();
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/manager/daily/{$kpi->id}/approve-all", ['date' => $date])
            ->assertOk()
            ->assertJsonPath('data.kpi_id', $kpi->id)
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.approved_count', $reviewable->count());

        $approved = KpiDailyEntry::whereIn('id', $reviewable->pluck('id'))->get();
        $this->assertTrue($approved->every(fn (KpiDailyEntry $entry): bool => $entry->manager_status === 'approved'
            && $entry->manager_assessed_by === $manager->id));
        $this->assertSame($beforeNotifications + 1, SystemNotification::count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'approve_all_daily_kpi_manager',
            'subject_type' => 'EmployeeKpi',
            'subject_id' => $kpi->id,
            'actor_id' => $manager->id,
        ]);

        $rated = $approved->first(fn (KpiDailyEntry $entry): bool => $entry->item->isManualRated());
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/manager/daily/{$rated->id}/assess", [
                'decision' => 'approved',
                'actual_json' => ['rating_code' => 'GOOD'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Perubahan penilaian Manager wajib menyertakan alasan.');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/manager/daily/{$rated->id}/assess", [
                'decision' => 'approved',
                'actual_json' => ['rating_code' => 'GOOD'],
                'note' => 'Observasi Manager menunjukkan hasil berbeda.',
            ])
            ->assertOk()
            ->assertJsonPath('data.effective_rubric_score', 85);
    }

    public function test_approve_all_rolls_back_when_one_entry_is_not_reviewable(): void
    {
        [$manager, $kpi, $date, $entries] = $this->approvedStaffEntries();
        $reviewable = KpiDailyEntry::whereIn('id', $entries->pluck('id'))->where('supervisor_status', 'approved')->get();
        $reviewable->last()->update(['entry_status' => 'draft']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/manager/daily/{$kpi->id}/approve-all", ['date' => $date])
            ->assertUnprocessable();

        $this->assertSame(0, KpiDailyEntry::whereIn('id', $reviewable->pluck('id'))->where('manager_status', 'approved')->count());
        $this->assertSame(0, AuditEvent::where('action', 'approve_all_daily_kpi_manager')->count());
    }

    private function approvedStaffEntries(): array
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
        $date = ServiceTicket::whereNotNull('completed_at')->orderBy('completed_at')->firstOrFail()->completed_at->toDateString();
        $employeeId = Employee::where('user_id', $employee->id)->value('id');
        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $employeeId)->firstOrFail();
        $entries = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $date)
            ->filter(fn (KpiDailyEntry $entry): bool => $entry->item->employee_kpi_id === $kpi->id)
            ->values();

        foreach ($entries as $entry) {
            app(DailyAssessmentService::class)->assessSupervisor(
                user: $supervisor,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $entry->item->isManualRated() || $entry->item->isSystemSourced() ? null : 80,
                actualJson: $entry->item->isManualRated() ? ['rating_code' => 'VERY_GOOD'] : null,
            );
        }

        return [$manager, $kpi, $date, $entries];
    }
}
