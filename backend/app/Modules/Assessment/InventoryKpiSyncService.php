<?php

namespace App\Modules\Assessment;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\StockOpname;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;

/**
 * Subsistem Inventory (stock opname) → KPI Gudang.
 * Feed:
 *  - GUD-01 Akurasi stok  = item akurat / total item dihitung (opname terakhir)
 *  - GUD-02 Selisih stok  = total |selisih| / total stok sistem (opname terakhir)
 *  - GUD-05 Opname        = sesi opname selesai / total sesi dalam periode
 */
class InventoryKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodInventoryData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->whereHas('employee.position', fn($q) => $q->where('code', 'POS-GUD'))
            ->get();

        $opnames = StockOpname::with('items')
            ->where('period_id', $period->id)
            ->get();

        $completedOpnames = $opnames->where('status', StockOpname::STATUS_COMPLETED);
        $latestOpname = $completedOpnames->sortByDesc('completed_at')->first();
        $totalOpnames = $opnames->count();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            if (!KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }

            $changed = false;

            // GUD-01 & GUD-02 dari opname terakhir yang selesai
            if ($latestOpname) {
                $counted = $latestOpname->items->where('is_counted', true);

                if ($counted->isNotEmpty()) {
                    $accurate = $counted->where('difference', 0)->count();
                    $accuracy = round(($accurate / $counted->count()) * 100, 2);

                    $totalSystem = $counted->sum('system_stock');
                    $totalDifference = $counted->sum(fn($i) => abs($i->difference));
                    $selisih = $totalSystem > 0 ? round(($totalDifference / $totalSystem) * 100, 2) : null;

                    $gud01 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-01');
                    if ($gud01) {
                        $gud01->actual_decimal = $accuracy;
                        $gud01->status = 'draft';
                        $gud01->save();
                        $this->calculationEngine->calculateItem($gud01);
                        $updatedItems++;
                        $changed = true;
                    }

                    $gud02 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-02');
                    if ($gud02) {
                        $gud02->actual_decimal = $selisih;
                        $gud02->status = 'draft';
                        $gud02->save();
                        $this->calculationEngine->calculateItem($gud02);
                        $updatedItems++;
                        $changed = true;
                    }
                }
            }

            // GUD-05: penyelesaian opname periode
            if ($totalOpnames > 0) {
                $completion = round(($completedOpnames->count() / $totalOpnames) * 100, 2);

                $gud05 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-05');
                if ($gud05) {
                    $gud05->actual_decimal = $completion;
                    $gud05->status = 'draft';
                    $gud05->save();
                    $this->calculationEngine->calculateItem($gud05);
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
            'message' => "Inventory disinkronkan: {$updatedItems} indikator gudang pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
            'opname_total' => $totalOpnames,
            'opname_completed' => $completedOpnames->count(),
        ];
    }
}
