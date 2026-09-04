<?php

namespace App\Modules\Assessment;

use App\Models\Complaint;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;

/**
 * Subsistem Complaint Management → KPI.
 * Feed:
 *  - CS-05  Jumlah komplain terhadap Pelayan (lower is better) = count
 *  - SUP-04 Penyelesaian komplain tim tepat waktu = resolved sebelum SLA / total × 100
 */
class ComplaintKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodComplaintData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->get();

        $complaints = Complaint::whereBetween('complaint_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->get();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (!$emp || !$emp->position || !KpiWorkflow::canSystemSyncKpi($kpi)) continue;

            $changed = false;

            // CS-05: jumlah komplain terhadap Pelayan tersebut
            if ($emp->position->code === 'POS-CS') {
                $count = $complaints->where('employee_id', $emp->id)->count();

                $item = $kpi->items->firstWhere('definition_code_snapshot', 'CS-05');
                if ($item) {
                    $item->actual_decimal = (float) $count;
                    $item->status = 'draft';
                    $item->save();
                    $this->calculationEngine->calculateItem($item);
                    $updatedItems++;
                    $changed = true;
                }
            }

            // SUP-04: penyelesaian komplain tepat waktu untuk tim supervisor
            if ($emp->position->code === 'POS-SPV') {
                $teamIds = Employee::where('supervisor_id', $emp->id)->pluck('id');
                $teamComplaints = $complaints->whereIn('employee_id', $teamIds);
                $total = $teamComplaints->count();

                if ($total > 0) {
                    $resolvedOntime = $teamComplaints->filter(function ($c) {
                        return $c->status === Complaint::STATUS_RESOLVED
                            && $c->resolved_at !== null
                            && $c->sla_deadline !== null
                            && $c->resolved_at->lte($c->sla_deadline);
                    })->count();

                    $rate = round(($resolvedOntime / $total) * 100, 2);

                    $item = $kpi->items->firstWhere('definition_code_snapshot', 'SUP-04');
                    if ($item) {
                        $item->actual_decimal = $rate;
                        $item->status = 'draft';
                        $item->save();
                        $this->calculationEngine->calculateItem($item);
                        $updatedItems++;
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $kpi->calculateProgress();
                $updatedEmployees++;
            }
        }

        return [
            'success' => true,
            'message' => "Komplain disinkronkan: {$updatedItems} indikator pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }
}
