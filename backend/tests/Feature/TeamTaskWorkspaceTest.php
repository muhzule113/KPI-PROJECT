<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\KpiCalculationRun;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTaskWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_cannot_open_team_workspace(): void
    {
        $employee = User::where('email', 'kasir@toko.com')->firstOrFail();

        $this->actingAs($employee)->get('/app/team-tasks')->assertForbidden();
        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/team/tasks')->assertForbidden();
    }

    public function test_workspace_groups_dates_and_keeps_manager_staff_reviews_optional(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
        $firstDate = $period->start_date->copy();
        while ($firstDate->isWeekend()) {
            $firstDate->addDay();
        }
        $secondDate = $firstDate->copy()->addDay();
        while ($secondDate->isWeekend()) {
            $secondDate->addDay();
        }
        $dates = [$firstDate->toDateString(), $secondDate->toDateString()];
        foreach ($dates as $date) {
            app(DailyAssessmentService::class)->supervisorQueue($supervisor, $date);
        }
        $revisionEntry = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $dates[1])->firstOrFail();
        $revisionEntry->update(['entry_status' => 'revision_required', 'supervisor_status' => 'revision_required']);

        $response = $this->actingAs($supervisor, 'sanctum')->getJson('/api/v1/team/tasks')
            ->assertOk()
            ->assertJsonPath('data.role', 'supervisor')
            ->assertJsonPath('data.title', 'Penilaian Tim');
        $employees = collect($response->json('data.employees'));
        $listedKpis = EmployeeKpi::whereIn('id', $employees->flatMap(fn (array $employee) => $employee['required_tasks'])->pluck('kpi_id')->unique())->get();
        $this->assertTrue($listedKpis->every(fn (EmployeeKpi $kpi): bool => (string) $kpi->supervisor_id_snapshot === (string) $supervisor->employee->id
            && (string) $kpi->branch_id_snapshot === (string) $supervisor->employee->branch_id
            && (string) $kpi->employee_id !== (string) $supervisor->employee->id));
        $this->assertTrue($employees->contains(fn (array $employee): bool => collect($employee['required_tasks'])
            ->pluck('date')->filter()->unique()->count() >= 2));
        $this->assertTrue($employees->flatMap(fn (array $employee) => $employee['required_tasks'])->contains('type', 'attendance'));
        $firstTasks = collect($employees->first()['required_tasks']);
        $this->assertSame($firstTasks->min('priority'), $firstTasks->first()['priority']);
        $this->assertSame('revision', $firstTasks->first()['type']);
        $this->assertSame($firstTasks->first()['action'], $employees->first()['primary_action']);

        $filtered = $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/team/tasks?date={$dates[0]}")
            ->assertOk()->json('data.employees');
        $filteredTasks = collect($filtered)->flatMap(fn (array $employee) => $employee['required_tasks']);
        $this->assertTrue($filteredTasks->every(fn (array $task): bool => $task['date'] === $dates[0]));

        $staffEntry = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $dates[0])
            ->first(fn (KpiDailyEntry $entry): bool => $entry->item->isManualRated());
        $this->assertNotNull($staffEntry);
        app(DailyAssessmentService::class)->assessSupervisor(
            user: $supervisor,
            entryId: $staffEntry->id,
            decision: 'approved',
            actualJson: ['rating_code' => 'VERY_GOOD'],
        );
        app(DailyAssessmentService::class)->managerQueue($manager, $dates[0]);

        $managerData = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/team/tasks')
            ->assertOk()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.title', 'Penilaian Tim')
            ->json('data');
        $required = collect($managerData['employees'])->flatMap(fn (array $employee) => $employee['required_tasks']);
        $optional = collect($managerData['employees'])->flatMap(fn (array $employee) => $employee['optional_tasks']);
        $managerKpis = EmployeeKpi::whereIn('id', $required->concat($optional)->pluck('kpi_id')->unique())->get();
        $this->assertTrue($managerKpis->every(fn (EmployeeKpi $kpi): bool => (string) $kpi->manager_id_snapshot === (string) $manager->employee->id
            && (string) $kpi->branch_id_snapshot === (string) $manager->employee->branch_id
            && (string) $kpi->employee_id !== (string) $manager->employee->id));
        $this->assertSame(count($required), $managerData['required_count']);
        $this->assertGreaterThan(0, $managerData['optional_count']);
        $this->assertTrue($optional->every(fn (array $task): bool => $task['type'] === 'staff_daily_review'));
        $this->assertFalse($required->contains(fn (array $task): bool => $task['type'] === 'staff_daily_review'));

        $this->actingAs($manager)->get('/app/team-tasks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TeamTasks')
                ->where('title', 'Penilaian Tim')
                ->where('date', today()->toDateString()));

        $this->actingAs($manager)->get('/app/team-tasks?date=')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('date', null));
    }

    public function test_completed_tab_requires_prepared_work_and_no_pending_task(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update(['review_deadline' => now()->addDays(2)]);
        $date = $period->start_date->copy();
        while ($date->isWeekend()) {
            $date->addDay();
        }
        $date = $date->toDateString();
        $queue = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $date);
        $kpiId = (string) $queue->firstOrFail()->item->employee_kpi_id;

        KpiDailyEntry::whereHas('item', fn ($query) => $query->where('employee_kpi_id', $kpiId))
            ->whereDate('entry_date', $date)
            ->update(['supervisor_status' => 'approved']);

        $data = $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/team/tasks?date={$date}")
            ->assertOk()->json('data');
        $this->assertFalse(collect($data['employees'])->contains(fn (array $row): bool => collect($row['periods'])->contains('id', EmployeeKpi::findOrFail($kpiId)->period_id)
            && (string) $row['employee']['id'] === (string) EmployeeKpi::findOrFail($kpiId)->employee_id));
        $this->assertTrue(collect($data['completed_employees'])->contains(
            fn (array $row): bool => (string) $row['employee']['id'] === (string) EmployeeKpi::findOrFail($kpiId)->employee_id
        ));
    }

    public function test_daily_queue_can_focus_one_authorized_kpi_and_rejects_another_scope(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update(['review_deadline' => now()->addDays(2)]);
        $date = $period->start_date->copy();
        while ($date->isWeekend()) {
            $date->addDay();
        }
        $date = $date->toDateString();
        $legacy = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $date);
        $kpiId = (string) $legacy->firstOrFail()->item->employee_kpi_id;

        $focused = $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/v1/supervisor/daily?date={$date}&kpi_id={$kpiId}")
            ->assertOk()->json('data');
        $this->assertNotEmpty($focused);
        $this->assertTrue(collect($focused)->every(fn (array $entry): bool => (string) $entry['kpi_id'] === $kpiId));
        $this->assertGreaterThanOrEqual(count($focused), $legacy->count());

        $managerKpi = EmployeeKpi::where('employee_id', $supervisor->employee->id)->firstOrFail();
        $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/v1/supervisor/daily?date={$date}&kpi_id={$managerKpi->id}")
            ->assertForbidden();
    }

    public function test_supervisor_can_confirm_only_complete_automatic_indicators_in_one_batch(): void
    {
        [$supervisor, $kpi, $date, $automatic] = $this->automaticEntries();
        $manualBefore = KpiDailyEntry::whereHas('item', fn ($query) => $query->where('employee_kpi_id', $kpi->id))
            ->whereDate('entry_date', $date)
            ->whereNotIn('id', $automatic->pluck('id'))
            ->pluck('supervisor_status', 'id');
        $sourceBefore = $automatic->mapWithKeys(fn (KpiDailyEntry $entry): array => [(string) $entry->id => [
            $entry->system_actual_decimal, $entry->system_actual_json, $entry->item->actual_decimal, $entry->item->actual_json,
        ]])->all();
        $notificationCount = SystemNotification::count();
        $calculationCount = KpiCalculationRun::where('employee_kpi_id', $kpi->id)->count();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/supervisor/daily/{$kpi->id}/approve-all", ['date' => $date])
            ->assertOk()
            ->assertJsonPath('data.kpi_id', $kpi->id)
            ->assertJsonPath('data.approved_count', $automatic->count());

        $approved = KpiDailyEntry::with('item')->whereIn('id', $automatic->pluck('id'))->get();
        $this->assertTrue($approved->every(fn (KpiDailyEntry $entry): bool => $entry->supervisor_status === 'approved'
            && $entry->supervisor_assessed_by === $supervisor->id));
        foreach ($approved as $entry) {
            $this->assertSame($sourceBefore[(string) $entry->id], [
                $entry->system_actual_decimal, $entry->system_actual_json, $entry->item->actual_decimal, $entry->item->actual_json,
            ]);
        }
        foreach ($manualBefore as $id => $status) {
            $this->assertSame($status, KpiDailyEntry::findOrFail($id)->supervisor_status);
        }
        $this->assertSame($notificationCount + 1, SystemNotification::count());
        $this->assertSame($calculationCount + 1, KpiCalculationRun::where('employee_kpi_id', $kpi->id)->count());
        $this->assertSame(1, AuditEvent::where('action', 'approve_all_automatic_daily_kpi_supervisor')
            ->where('subject_id', $kpi->id)->count());
    }

    public function test_automatic_confirmation_rolls_back_when_one_source_is_incomplete(): void
    {
        [$supervisor, $kpi, $date, $automatic] = $this->automaticEntries();
        $incomplete = $automatic->first();
        KpiDailyEntry::withoutEvents(function () use ($incomplete): void {
            $incomplete->forceFill(['system_actual_decimal' => null])->save();
        });
        if (data_get($incomplete->system_actual_json, 'cadence') === 'period') {
            $incomplete->item->withoutEvents(fn () => $incomplete->item->forceFill(['actual_decimal' => null])->save());
        }

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/supervisor/daily/{$kpi->id}/approve-all", ['date' => $date])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Data otomatis {$incomplete->item->definition_code_snapshot} belum lengkap. Tidak ada indikator yang diubah.");

        $this->assertSame(0, KpiDailyEntry::whereIn('id', $automatic->pluck('id'))->where('supervisor_status', 'approved')->count());
        $this->assertSame(0, AuditEvent::where('action', 'approve_all_automatic_daily_kpi_supervisor')->count());
    }

    public function test_locked_period_is_excluded_from_workspace(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        KpiPeriod::query()->update(['status' => 'LOCKED']);

        $this->actingAs($supervisor, 'sanctum')->getJson('/api/v1/team/tasks')
            ->assertOk()
            ->assertJsonPath('data.required_count', 0)
            ->assertJsonPath('data.optional_count', 0)
            ->assertJsonPath('data.empty_state.code', 'no_active_period');
    }

    private function automaticEntries(): array
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
        $date = ServiceTicket::whereNotNull('completed_at')->orderBy('completed_at')->firstOrFail()->completed_at->toDateString();
        $entries = app(DailyAssessmentService::class)->supervisorQueue($supervisor, $date);
        $candidate = $entries->groupBy(fn (KpiDailyEntry $entry): string => (string) $entry->item->employee_kpi_id)
            ->map(fn ($group) => $group->filter(fn (KpiDailyEntry $entry): bool => $entry->item->isSystemSourced()
                && ! $entry->item->isAttendanceIndicator()
                && (data_get($entry->system_actual_json, 'cadence') === 'period'
                    ? $entry->item->systemActualDecimal() !== null
                    : $entry->system_actual_decimal !== null))->values())
            ->first(fn ($group): bool => $group->isNotEmpty());
        $this->assertNotNull($candidate, 'Seeder harus menyediakan minimal satu indikator otomatis lengkap.');
        $kpi = EmployeeKpi::findOrFail($candidate->first()->item->employee_kpi_id);
        $allAutomatic = $entries->filter(fn (KpiDailyEntry $entry): bool => $entry->item->employee_kpi_id === $kpi->id
            && $entry->item->isSystemSourced() && ! $entry->item->isAttendanceIndicator())->values();
        $this->assertTrue($allAutomatic->every(fn (KpiDailyEntry $entry): bool => data_get($entry->system_actual_json, 'cadence') === 'period'
            ? $entry->item->systemActualDecimal() !== null : $entry->system_actual_decimal !== null));

        return [$supervisor, $kpi, $date, $allAutomatic];
    }
}
