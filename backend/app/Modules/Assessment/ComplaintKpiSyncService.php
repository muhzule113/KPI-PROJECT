<?php

namespace App\Modules\Assessment;

use App\Models\Complaint;
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
            if (! $emp || ! KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }

            $changed = false;

            // CS-05: jumlah komplain terhadap Pelayan tersebut
            if ($kpi->position_code_snapshot === 'POS-CS') {
                $complaintCount = $complaints
                    ->where('employee_id', $emp->id)
                    ->filter(fn (Complaint $complaint): bool => (bool) $complaint->description && (bool) $complaint->channel)
                    ->unique(fn (Complaint $complaint): string => $complaint->service_ticket_id
                        ? "ticket:{$complaint->service_ticket_id}"
                        : "complaint:{$complaint->id}")
                    ->count();
                $item = $kpi->items->firstWhere('definition_code_snapshot', 'CS-05');
                if ($item) {
                    $item->actual_decimal = $complaintCount;
                    $item->actual_json = [
                        '_system_calculated' => true,
                        'formula' => 'komplain valid unik / tiket servis eligible Pelayan × 100',
                        'valid_complaints' => $complaintCount,
                    ];
                    $item->status = 'draft';
                    $item->save();
                    $this->calculationEngine->calculateItem($item);
                    $updatedItems++;
                    $changed = true;
                }
            }

            // SUP-04: penyelesaian komplain tepat waktu untuk tim supervisor
            if ($kpi->position_code_snapshot === 'POS-SPV') {
                $teamIds = EmployeeKpi::where('period_id', $period->id)
                    ->where('supervisor_id_snapshot', $emp->id)->pluck('employee_id');
                $teamComplaints = $complaints->whereIn('employee_id', $teamIds);
                $total = $teamComplaints->count();

                $resolvedOntime = $teamComplaints->filter(function ($c) {
                    return $c->status === Complaint::STATUS_RESOLVED
                        && $c->resolved_at !== null
                        && $c->sla_deadline !== null
                        && $c->resolved_at->lte($c->sla_deadline);
                })->count();
                $rate = $total > 0 ? round(($resolvedOntime / $total) * 100, 2) : null;

                $item = $kpi->items->firstWhere('definition_code_snapshot', 'SUP-04');
                if ($item) {
                    $item->actual_decimal = $rate;
                    $item->actual_json = [
                        '_system_calculated' => true,
                        'resolved_ontime' => $resolvedOntime,
                        'total_complaints' => $total,
                    ];
                    $item->status = 'draft';
                    $item->save();
                    $this->calculationEngine->calculateItem($item);
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
            'message' => "Komplain disinkronkan: {$updatedItems} indikator pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }
}
