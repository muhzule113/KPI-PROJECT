<?php

namespace App\Modules\Assessment;

use App\Models\CoachingLog;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;

/**
 * Subsistem Coaching Log → KPI Supervisor.
 * Feed:
 *  - SUP-05 Coaching & evaluasi = cakupan karyawan yang dicoach / total anggota tim × 100
 */
class CoachingKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodCoachingData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->whereHas('employee.position', fn($q) => $q->where('code', 'POS-SPV'))
            ->get();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $spv = $kpi->employee;
            if (!$spv || !KpiWorkflow::canSystemSyncKpi($kpi)) continue;

            $teamSize = Employee::where('supervisor_id', $spv->id)
                ->where('status', 'active')
                ->count();

            if ($teamSize === 0) continue;

            $coachedCount = CoachingLog::where('supervisor_id', $spv->id)
                ->whereBetween('coaching_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
                ->distinct('employee_id')
                ->count('employee_id');

            $coverage = round(($coachedCount / $teamSize) * 100, 2);

            $item = $kpi->items->firstWhere('definition_code_snapshot', 'SUP-05');
            if (!$item) continue;

            $item->actual_decimal = $coverage;
            $item->status = 'draft';
            $item->save();
            $this->calculationEngine->calculateItem($item);
            $updatedItems++;
            $kpi->calculateProgress();
            $updatedEmployees++;
        }

        return [
            'success' => true,
            'message' => "Coaching log disinkronkan: {$updatedItems} indikator pada {$updatedEmployees} supervisor.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }
}
