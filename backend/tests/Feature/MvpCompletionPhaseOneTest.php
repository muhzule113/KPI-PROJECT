<?php

namespace Tests\Feature;

use App\Models\AdminWorkLog;
use App\Models\CashierTransaction;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Models\ReportSubmission;
use App\Modules\Assessment\CashierKpiSyncService;
use App\Modules\Reporting\ReportSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MvpCompletionPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_cashier_kpi_uses_all_committed_batches_and_absolute_cash_differences(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $cashier = Employee::where('employee_number', 'EMP-006')->firstOrFail();
        $uploader = $cashier->user;

        foreach ([
            ['id' => '01KPI00000000000000000001', 'confirmed_at' => $period->submission_deadline->copy()->subHour(), 'diffs' => [40000, -30000]],
            ['id' => '01KPI00000000000000000002', 'confirmed_at' => $period->submission_deadline->copy()->addHour(), 'diffs' => [-50000]],
        ] as $batchData) {
            $batch = ImportBatch::create([
                'id' => $batchData['id'],
                'file_name' => $batchData['id'].'.csv',
                'file_path' => 'imports/'.$batchData['id'].'.csv',
                'file_hash_sha256' => hash('sha256', $batchData['id']),
                'source_application' => 'POS_SYSTEM',
                'period_id' => $period->id,
                'uploader_id' => $uploader->id,
                'status' => 'confirmed',
                'confirmed_at' => $batchData['confirmed_at'],
                'confirmed_by' => $uploader->id,
            ]);

            foreach ($batchData['diffs'] as $index => $difference) {
                CashierTransaction::create([
                    'import_batch_id' => $batch->id,
                    'period_id' => $period->id,
                    'source_application' => 'POS_SYSTEM',
                    'cashier_employee_id' => $cashier->id,
                    'cashier_name_raw' => $cashier->name,
                    'transaction_number' => $batch->id.'-'.$index,
                    'business_key' => 'POS_SYSTEM|'.$batch->id.'-'.$index,
                    'transaction_date' => $period->start_date->copy()->addDays($index + 1),
                    'transaction_amount' => 100000,
                    'system_cash_amount' => 100000,
                    'actual_cash_amount' => 100000 + $difference,
                    'cash_difference' => $difference,
                    'duration_seconds' => $index === 0 ? 120 : 240,
                    'status' => $index === 0 ? 'SUCCESS' : 'VOID',
                ]);
            }
        }

        app(CashierKpiSyncService::class)->syncPeriod($period);

        $items = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $cashier->id)
            ->firstOrFail()->items()->pluck('actual_decimal', 'definition_code_snapshot');
        $this->assertSame(120000.0, (float) $items['KSR-02']);
        $this->assertSame(50.0, (float) $items['KSR-03']);
        $this->assertSame(66.666667, (float) $items['KSR-01']);
        $this->assertSame(66.666667, (float) $items['KSR-04']);
    }

    public function test_report_submission_schedule_and_scores_do_not_treat_future_deadlines_as_zero(): void
    {
        Carbon::setTestNow('2026-08-03 17:00:00', 'Asia/Makassar');
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $admin = Employee::where('employee_number', 'EMP-005')->firstOrFail();
        $supervisor = Employee::where('employee_number', 'EMP-002')->firstOrFail();
        $service = app(ReportSubmissionService::class);
        $service->schedule($period);

        $daily = ReportSubmission::where('period_id', $period->id)
            ->where('employee_id', $admin->id)->where('report_type', 'admin_daily')->oldest('report_date')->firstOrFail();
        AdminWorkLog::create([
            'employee_id' => $admin->id,
            'period_id' => $period->id,
            'work_date' => $daily->report_date,
            'records_input' => 1,
            'records_corrected' => 0,
            'documents_eligible' => 1,
            'documents_complete' => 1,
            'reconciliations_total' => 1,
            'reconciliations_success' => 1,
        ]);
        $service->submit($daily, $admin->user);
        $service->syncPeriod($period);

        $adminItem = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $admin->id)
            ->firstOrFail()->items()->where('definition_code_snapshot', 'ADM-02')->firstOrFail();
        $supervisorItem = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $supervisor->id)
            ->firstOrFail()->items()->where('definition_code_snapshot', 'SUP-07')->firstOrFail();

        $this->assertSame(100.0, (float) $adminItem->actual_decimal);
        $this->assertNull($supervisorItem->actual_decimal);
        $this->assertSame('submitted', $daily->fresh()->status);
    }
}
