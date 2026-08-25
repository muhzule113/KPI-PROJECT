<?php

namespace App\Modules\Assessment;

use App\Models\AdminWorkLog;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;

/**
 * Subsistem Admin Work-Log → KPI Admin.
 * Feed:
 *  - ADM-01 Akurasi input data = (input − koreksi) / input × 100
 *  - ADM-03 Kelengkapan dokumen = dokumen lengkap / eligible × 100
 *  - ADM-04 Rekonsiliasi data = rekonsiliasi sukses / total × 100
 *
 * Admin mencatat FAKTA kerja harian; sistem yang menghitung persentase.
 */
class AdminWorkLogKpiSyncService
{
    public const WORKLOG_ITEM_CODES = ['ADM-01', 'ADM-03', 'ADM-04'];

    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodWorkLogData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->whereHas('employee.position', fn($q) => $q->where('code', 'POS-ADM'))
            ->get();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (!$emp) continue;

            $logs = AdminWorkLog::where('employee_id', $emp->id)
                ->whereBetween('work_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
                ->get();

            if ($logs->isEmpty()) continue;

            $changed = false;

            // ADM-01: akurasi input
            $input = $logs->sum('records_input');
            $corrected = $logs->sum('records_corrected');
            if ($input > 0) {
                $accuracy = round((($input - $corrected) / $input) * 100, 2);
                if ($this->setItemActual($kpi, 'ADM-01', $accuracy)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            // ADM-03: kelengkapan dokumen
            $eligible = $logs->sum('documents_eligible');
            $complete = $logs->sum('documents_complete');
            if ($eligible > 0) {
                $completeness = round(($complete / $eligible) * 100, 2);
                if ($this->setItemActual($kpi, 'ADM-03', $completeness)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            // ADM-04: rekonsiliasi
            $total = $logs->sum('reconciliations_total');
            $success = $logs->sum('reconciliations_success');
            if ($total > 0) {
                $reconRate = round(($success / $total) * 100, 2);
                if ($this->setItemActual($kpi, 'ADM-04', $reconRate)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            if ($changed) {
                $kpi->calculateProgress();
                $updatedEmployees++;
            }
        }

        return [
            'success' => true,
            'message' => "Admin work-log disinkronkan: {$updatedItems} indikator pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }

    protected function setItemActual(EmployeeKpi $kpi, string $code, float $value): bool
    {
        $item = $kpi->items->firstWhere('definition_code_snapshot', $code);
        if (!$item) return false;

        $item->actual_decimal = $value;
        $item->status = 'draft';
        $item->save();
        $this->calculationEngine->calculateItem($item);

        return true;
    }
}
