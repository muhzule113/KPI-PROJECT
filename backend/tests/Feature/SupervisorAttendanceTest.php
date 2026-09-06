<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeKpi;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Assessment\AttendanceKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisorAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_supervisor_can_check_team_attendance_and_sync_kpi(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $team = EmployeeKpi::with('employee')
            ->where('period_id', $period->id)
            ->where('supervisor_id_snapshot', $supervisor->employee->id)
            ->get();
        $this->assertNotEmpty($team);

        $date = now()->toDateString();
        $statuses = $team->mapWithKeys(fn (EmployeeKpi $kpi): array => [(string) $kpi->employee_id => Attendance::STATUS_PRESENT])->all();
        $firstEmployeeId = (string) $team->first()->employee_id;
        $statuses[$firstEmployeeId] = Attendance::STATUS_PERMISSION;

        $this->actingAs($supervisor)
            ->get("/app/supervisor-attendance?date={$date}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SupervisorAttendance')
                ->has('rows', $team->count())
                ->where('rows.0.employee_id', $team->sortBy('employee.name')->first()->employee_id));

        $this->actingAs($supervisor)
            ->post('/app/supervisor-attendance', [
                'date' => $date,
                'statuses' => $statuses,
                'notes' => [$firstEmployeeId => 'Izin disetujui Supervisor'],
            ])
            ->assertRedirect("/app/supervisor-attendance?date={$date}");

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $firstEmployeeId,
            'attendance_date' => $date,
            'status' => Attendance::STATUS_PERMISSION,
            'recorded_by' => $supervisor->id,
        ]);
        $this->assertSame(
            $team->count(),
            Attendance::whereIn('employee_id', $team->pluck('employee_id'))->whereDate('attendance_date', $date)->count()
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'supervisor_attendance_recorded',
            'actor_id' => $supervisor->id,
        ]);

        $attendanceItem = $team->first()->items()
            ->whereIn('definition_code_snapshot', AttendanceKpiSyncService::ATTENDANCE_ITEM_CODES)
            ->first();
        if ($attendanceItem) {
            $entry = KpiDailyEntry::where('employee_kpi_item_id', $attendanceItem->id)
                ->whereDate('entry_date', $date)
                ->firstOrFail();
            $this->assertSame('submitted', $entry->entry_status);
            $this->assertNotNull($entry->system_actual_decimal);
        }
    }

    public function test_employee_cannot_open_supervisor_attendance_board(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($employee)
            ->get('/app/supervisor-attendance')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }
}
