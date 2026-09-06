<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected DailyAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DailyAssessmentService::class);
    }

    public function test_employee_can_view_but_cannot_save_owned_daily_kpi(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = now()->toDateString();
        $day = $this->service->employeeDay($employee, $date);

        $this->assertNotEmpty($day['entries']);
        $employeeItem = $day['kpi']->items->firstWhere('definition_code_snapshot', 'TEK-01');
        $entry = $day['entries']->firstWhere('employee_kpi_item_id', $employeeItem->id);
        $this->assertSame('submitted', $entry->entry_status);

        $this->expectException(AuthorizationException::class);
        $this->service->saveEmployeeDay($employee, $date, [[
            'item_id' => $employeeItem->id,
            'actual_decimal' => 12,
        ]]);
    }

    public function test_supervisor_completes_staff_daily_review_without_employee_input_or_manager_daily_review(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();

        $queue = $this->service->supervisorQueue($supervisor, $date);
        $employeeQueue = $queue->filter(fn (KpiDailyEntry $entry) => (string) $entry->item->employee_kpi_id === (string) $kpi->id)->values();
        $this->assertCount($kpi->items()->count(), $employeeQueue);

        foreach ($employeeQueue as $entry) {
            $answers = $entry->item->formula_key_snapshot === 'rubric'
                ? collect($entry->item->rubric_snapshot['criteria'] ?? [])->map(fn (array $criterion) => [
                    'criterion_id' => $criterion['id'],
                    'is_fulfilled' => true,
                ])->all()
                : null;

            $this->service->assessSupervisor(
                user: $supervisor,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $answers || $entry->item->isSystemSourced() ? null : 80,
                answers: $answers
            );
        }

        $managerQueue = $this->service->managerQueue($manager, $date)
            ->filter(fn (KpiDailyEntry $entry): bool => (string) $entry->item->employee_kpi_id === (string) $kpi->id)
            ->values();
        $this->assertCount(0, $managerQueue);

        $serviceItem = $kpi->items()->where('definition_code_snapshot', 'TEK-01')->firstOrFail()->fresh();
        $this->assertSame(80.0, (float) $serviceItem->actual_decimal);
        $this->assertSame('sum', $serviceItem->actual_json['aggregation']);
    }

    public function test_manager_cannot_assess_staff_and_supervisor_cannot_assess_as_manager(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = now()->toDateString();
        $this->service->employeeDay($employee, $date);
        $entry = KpiDailyEntry::whereDate('entry_date', $date)->firstOrFail();

        try {
            $this->service->assessManager($manager, $entry->id, 'approved', 45);
            $this->fail('Manager tidak boleh menilai harian staf.');
        } catch (\Exception $exception) {
            $this->assertSame('Anda tidak berwenang menilai KPI harian ini.', $exception->getMessage());
        }

        try {
            $this->service->assessManager($supervisor, $entry->id, 'approved', 45);
            $this->fail('Supervisor tidak boleh menggunakan alur penilaian Manager.');
        } catch (\Exception $exception) {
            $this->assertSame('Peran Anda tidak dapat melakukan tindakan ini.', $exception->getMessage());
        }
    }

    public function test_system_creates_daily_queue_by_cadence_without_manager_staff_tasks(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();
        $supervisorRecord = Employee::where('user_id', $supervisor->id)->firstOrFail();
        $assignedKpis = EmployeeKpi::with('items')
            ->where('period_id', $period->id)
            ->where('supervisor_id_snapshot', $supervisorRecord->id)
            ->get();
        $queue = $this->service->supervisorQueue($supervisor, $date);
        $employeeQueue = $queue->filter(fn (KpiDailyEntry $entry) => (string) $entry->item->employee_kpi_id === (string) $kpi->id)->values();
        $this->assertCount($kpi->items()->count(), $employeeQueue);
        foreach ($assignedKpis as $assignedKpi) {
            foreach ($assignedKpi->items->filter(fn ($item) => ! $item->isSystemSourced() && ! $item->isAttendanceIndicator()) as $item) {
                $this->assertTrue($queue->contains('employee_kpi_item_id', $item->id));
            }
        }
        $beforeCount = KpiDailyEntry::count();
        $this->service->supervisorQueue($supervisor, $date);
        $this->assertSame($beforeCount, KpiDailyEntry::count());
        $this->assertSame($assignedKpis->count(), SystemNotification::where('type', 'daily_kpi_ready')->count());

        foreach ($employeeQueue as $index => $entry) {
            $answers = $entry->item->formula_key_snapshot === 'rubric'
                ? collect($entry->item->rubric_snapshot['criteria'] ?? [])->map(fn (array $criterion) => [
                    'criterion_id' => $criterion['id'],
                    'is_fulfilled' => true,
                ])->all()
                : null;

            $this->service->assessSupervisor(
                user: $supervisor,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $answers || $entry->item->isSystemSourced() ? null : 80,
                answers: $answers
            );

            $managerQueue = $this->service->managerQueue($manager, $date)
                ->filter(fn (KpiDailyEntry $candidate): bool => (string) $candidate->item->employee_kpi_id === (string) $kpi->id)
                ->values();
            if ($index < $employeeQueue->count() - 1) {
                $this->assertCount(0, $managerQueue);
            }
        }

        $this->assertCount(
            0,
            $this->service->managerQueue($manager, $date)
                ->filter(fn (KpiDailyEntry $candidate): bool => (string) $candidate->item->employee_kpi_id === (string) $kpi->id)
        );
        $this->assertSame(0, SystemNotification::where('type', 'daily_kpi_reviewed')->count());
    }

    public function test_system_indicator_retains_official_period_value_after_supervisor_review(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();
        $item = $kpi->items()->where('definition_code_snapshot', 'TEK-07')->firstOrFail();
        $item->update(['actual_decimal' => 70, 'actual_json' => ['seed_value' => 70]]);

        $this->service->employeeDay($employee, $date);
        $entry = KpiDailyEntry::whereDate('entry_date', $date)
            ->where('employee_kpi_item_id', $item->id)
            ->firstOrFail();
        $entry->update([
            'system_actual_decimal' => 10,
            'system_actual_json' => ['source' => 'test'],
            'entry_status' => 'submitted',
        ]);

        $supervisorEntry = $this->service->assessSupervisor($supervisor, $entry->id, 'approved');
        $this->assertSame('approved', $supervisorEntry->supervisor_status);
        $this->assertNull($supervisorEntry->supervisor_actual_decimal);

        $this->assertSame('pending', $supervisorEntry->manager_status);
        $this->assertNull($supervisorEntry->manager_actual_decimal);
        $this->assertSame(70.0, (float) $item->fresh()->actual_decimal);
        $this->assertFalse((bool) data_get($item->fresh()->actual_json, '_daily_aggregate', false));
    }
}
