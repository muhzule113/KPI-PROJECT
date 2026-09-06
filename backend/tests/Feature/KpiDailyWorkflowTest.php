<?php

namespace Tests\Feature;

use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiCalculationRun;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Period\PeriodService;
use App\Modules\Review\ReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class KpiDailyWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function kpi(User $employee, KpiPeriod $period, User $supervisor, User $manager): EmployeeKpi
    {
        $template = EmployeeKpi::where('employee_id', $employee->employee->id)->firstOrFail();

        return EmployeeKpi::create([
            'employee_id' => $employee->employee->id,
            'period_id' => $period->id,
            'template_version_id' => $template->template_version_id,
            'supervisor_id_snapshot' => $supervisor->employee->id,
            'manager_id_snapshot' => $manager->employee->id,
            'branch_id_snapshot' => $employee->employee->branch_id,
            'position_id_snapshot' => $employee->employee->position_id,
            'position_code_snapshot' => $employee->employee->position->code,
            'status' => 'submitted',
        ]);
    }

    public function test_staff_and_supervisor_complete_with_one_manager_then_publish_and_lock_separately(): void
    {
        $staff = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail()->replicate();
        $period->name = 'Siklus terisolasi';
        $period->year = 2099;
        $period->month = 1;
        $period->start_date = now()->toDateString();
        $period->end_date = now()->toDateString();
        $period->submission_deadline = now()->addDay();
        $period->review_deadline = now()->addDays(2);
        $period->approval_deadline = now()->addDays(3);
        $period->save();
        $staffKpi = $this->kpi($staff, $period, $supervisor, $manager);
        $supervisorKpi = $this->kpi($supervisor, $period, $manager, $manager);
        $baseItem = EmployeeKpiItem::firstOrFail();
        foreach ([$staffKpi, $supervisorKpi] as $kpi) {
            $item = $baseItem->replicate();
            $item->employee_kpi_id = $kpi->id;
            $item->source_type_snapshot = 'employee';
            $item->formula_key_snapshot = 'higher_is_better';
            $item->target_value_snapshot = 100;
            $item->weight_snapshot = 100;
            $item->actual_decimal = null;
            $item->actual_json = null;
            $item->status = 'not_started';
            $item->save();
            $entry = KpiDailyEntry::create(['employee_kpi_item_id' => $item->id, 'entry_date' => now()->toDateString(), 'entry_status' => 'submitted']);
            $service = app(DailyAssessmentService::class);
            if ($kpi->isSupervisorKpi()) {
                $service->assessManager($manager, $entry->id, 'approved', 100);
            } else {
                $service->assessSupervisor($supervisor, $entry->id, 'approved', 100);
                $this->assertSame('pending', $entry->fresh()->manager_status);
                app(ReviewService::class)->forwardToManager($kpi, reviewerId: $supervisor->id);
            }
            $result = app(ApprovalService::class)->approve($kpi, approverId: $manager->id);
            $this->assertTrue($result['success']);
            $this->assertNull($kpi->fresh()->locked_at);
            $this->assertSame('approved', $kpi->fresh()->status);
            $this->assertSame('verified', $item->fresh()->status);
        }
        $period->update(['status' => 'WAITING_APPROVAL']);
        app(PeriodService::class)->publishPeriod($period);
        $this->assertNotNull($period->fresh()->published_at);
        $this->assertNull($period->fresh()->locked_at);
        app(PeriodService::class)->lockPeriod($period->fresh());
        $this->assertSame('locked', $staffKpi->fresh()->status);
        $this->assertSame('locked', $supervisorKpi->fresh()->status);
    }

    public function test_source_revision_invalidates_review_and_repeated_sync_keeps_daily_identity(): void
    {
        $staff = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $kpi = EmployeeKpi::where('employee_id', $staff->employee->id)->firstOrFail();
        $item = $kpi->items()->firstOrFail();
        $item->update(['source_type_snapshot' => 'system', 'actual_decimal' => 100]);
        $item->update(['status' => 'verified', 'manager_decision' => 'valid']);
        $kpi->update(['status' => 'pending_approval']);
        $entry = KpiDailyEntry::create([
            'employee_kpi_item_id' => $item->id, 'entry_date' => '2026-08-01',
            'entry_status' => 'submitted', 'system_actual_decimal' => 100,
            'system_actual_json' => ['cadence' => 'period'], 'supervisor_status' => 'approved',
        ]);
        $item->update(['actual_decimal' => 80]);
        $this->assertSame('under_review', $kpi->fresh()->status);
        $this->assertNull($item->fresh()->manager_decision);
        $this->assertSame('pending', $entry->fresh()->supervisor_status);
        $version = $kpi->fresh()->row_version;
        $item->refresh()->update(['actual_decimal' => 80]);
        $this->assertSame($version, $kpi->fresh()->row_version);
        $this->assertSame(1, KpiDailyEntry::where('employee_kpi_item_id', $item->id)->whereDate('entry_date', '2026-08-01')->count());
    }

    public function test_scheduled_preparation_is_idempotent_without_opening_a_page(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $service = app(DailyAssessmentService::class);
        $service->preparePeriod($period, $period->start_date->toDateString());
        $entries = KpiDailyEntry::count();
        $runs = KpiCalculationRun::count();
        $service->preparePeriod($period, $period->start_date->toDateString());
        $this->assertSame($entries, KpiDailyEntry::count());
        $this->assertSame($runs, KpiCalculationRun::count());
        $this->assertSame(0, $period->employeeKpis()->where('status', 'draft')->count());
    }
}
