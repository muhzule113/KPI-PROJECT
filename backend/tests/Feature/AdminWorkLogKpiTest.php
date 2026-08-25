<?php

namespace Tests\Feature;

use App\Models\AdminWorkLog;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Assessment\AdminWorkLogKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkLogKpiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_work_log_sync_computes_admin_kpi_actuals(): void
    {
        $empAdm = Employee::where('email', 'admin_staff@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($empAdm, 'Admin staff harus ada di seeder');

        // 2 hari kerja log: total input 150 (2 koreksi), dokumen 20 (19 lengkap), rekonsiliasi 10 (9 sukses)
        AdminWorkLog::create([
            'employee_id' => $empAdm->id,
            'period_id' => $period->id,
            'work_date' => $period->start_date->copy()->addDay(),
            'records_input' => 100,
            'records_corrected' => 2,
            'documents_eligible' => 10,
            'documents_complete' => 9,
            'reconciliations_total' => 5,
            'reconciliations_success' => 5,
        ]);
        AdminWorkLog::create([
            'employee_id' => $empAdm->id,
            'period_id' => $period->id,
            'work_date' => $period->start_date->copy()->addDays(2),
            'records_input' => 50,
            'records_corrected' => 0,
            'documents_eligible' => 10,
            'documents_complete' => 10,
            'reconciliations_total' => 5,
            'reconciliations_success' => 4,
        ]);

        $res = app(AdminWorkLogKpiSyncService::class)->syncPeriodWorkLogData($period);
        $this->assertEquals(3, $res['updated_items']);

        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empAdm->id)->first();
        $adm01 = $kpi->items->firstWhere('definition_code_snapshot', 'ADM-01');
        $adm03 = $kpi->items->firstWhere('definition_code_snapshot', 'ADM-03');
        $adm04 = $kpi->items->firstWhere('definition_code_snapshot', 'ADM-04');

        $this->assertNotNull($adm01);
        $this->assertNotNull($adm03);
        $this->assertNotNull($adm04);

        // ADM-01: (150-2)/150 = 98.67
        $this->assertEquals(round(148 / 150 * 100, 2), (float) $adm01->actual_decimal);
        // ADM-03: 19/20 = 95.0
        $this->assertEquals(95.0, (float) $adm03->actual_decimal);
        // ADM-04: 9/10 = 90.0
        $this->assertEquals(90.0, (float) $adm04->actual_decimal);
    }

    public function test_work_log_sync_skips_when_no_logs(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $res = app(AdminWorkLogKpiSyncService::class)->syncPeriodWorkLogData($period);

        $this->assertEquals(0, $res['updated_items']);
        $this->assertEquals(0, $res['updated_employees']);
    }
}
