<?php

namespace App\Modules\Assessment;

use App\Models\CashierTransaction;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;

final class CashierKpiSyncService
{
    public function __construct(private KpiCalculationEngine $calculationEngine) {}

    public function syncPeriod(KpiPeriod $period): array
    {
        $updated = 0;
        $kpis = EmployeeKpi::with('items')->where('period_id', $period->id)
            ->where('position_code_snapshot', 'POS-KSR')->get();

        foreach ($kpis as $kpi) {
            if (! KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }
            $transactions = CashierTransaction::query()
                ->where('period_id', $period->id)
                ->where('cashier_employee_id', $kpi->employee_id)
                ->where('is_duplicate', false)
                ->where('status', '!=', 'SUPERSEDED')
                ->whereHas('importBatch', fn ($query) => $query->whereIn('status', ['confirmed', 'committed']))
                ->with('importBatch')->get();
            if ($transactions->isEmpty()) {
                continue;
            }

            $eligible = $transactions->count();
            $valid = $transactions->where('status', 'SUCCESS')->count();
            $timed = $transactions->whereNotNull('duration_seconds');
            $batchRows = $transactions->unique('import_batch_id');
            $onTimeBatches = $batchRows->filter(fn (CashierTransaction $transaction): bool => $transaction->importBatch?->confirmed_at?->lessThanOrEqualTo($period->submission_deadline) ?? false
            )->count();
            $values = [
                'KSR-01' => $valid / $eligible * 100,
                'KSR-02' => $transactions->sum(fn (CashierTransaction $transaction): float => abs((float) $transaction->actual_cash_amount - (float) $transaction->system_cash_amount)),
                'KSR-03' => $onTimeBatches / $batchRows->count() * 100,
                'KSR-04' => $timed->isEmpty() ? null : $timed->where('duration_seconds', '<=', 180)->count() / $timed->count() * 100,
            ];

            foreach ($values as $code => $actual) {
                $item = $kpi->items->firstWhere('definition_code_snapshot', $code);
                if (! $item || $actual === null) {
                    continue;
                }
                $item->actual_decimal = round($actual, 6, PHP_ROUND_HALF_UP);
                $item->actual_json = [
                    '_system_calculated' => true,
                    'eligible_transactions' => $eligible,
                    'valid_transactions' => $valid,
                    'unique_batches' => $batchRows->count(),
                    'on_time_batches' => $onTimeBatches,
                    'timed_transactions' => $timed->count(),
                ];
                $item->status = 'verified';
                $item->save();
                $this->calculationEngine->calculateItem($item);
                $updated++;
            }
            $kpi->calculateProgress();
            $this->calculationEngine->calculateKpi($kpi, 'cashier_sync');
        }

        return ['updated_items' => $updated, 'updated_employees' => $kpis->count()];
    }
}
