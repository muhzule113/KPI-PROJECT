<?php

namespace App\Modules\Calculation;

use App\Models\AuditEvent;
use App\Models\KpiPeriod;
use App\Modules\Assessment\OperationalKpiSyncService;
use Illuminate\Support\Facades\DB;

final class KpiAuditRepairService
{
    public function __construct(
        private OperationalKpiSyncService $sync,
        private KpiCalculationEngine $calculationEngine,
    ) {}

    public function run(?int $periodId = null, bool $apply = false): array
    {
        $periods = KpiPeriod::query()->when($periodId, fn ($query) => $query->whereKey($periodId))->get();
        $results = [];
        foreach ($periods as $period) {
            if ($period->isLocked()) {
                $results[] = ['period_id' => $period->id, 'status' => 'LOCKED', 'changed' => false, 'immutable' => true];

                continue;
            }
            $before = $this->snapshot($period);
            if ($apply) {
                $this->sync->syncPeriodOperationalData($period);
                foreach ($period->employeeKpis()->get() as $kpi) {
                    $this->calculationEngine->calculateKpi($kpi, 'repair');
                }
                $after = $this->snapshot($period);
                AuditEvent::log('kpi_audit_repair', 'KpiPeriod', (string) $period->id, before: $before, after: $after);
            } else {
                DB::beginTransaction();
                try {
                    $this->sync->syncPeriodOperationalData($period);
                    $after = $this->snapshot($period);
                } finally {
                    DB::rollBack();
                }
            }
            $results[] = [
                'period_id' => $period->id,
                'status' => $period->status,
                'changed' => $before !== $after,
                'before' => $before,
                'after' => $after,
                'applied' => $apply,
            ];
        }

        return $results;
    }

    private function snapshot(KpiPeriod $period): array
    {
        return $period->employeeKpis()->with('items')->get()->mapWithKeys(fn ($kpi) => [
            (string) $kpi->id => [
                'final_score' => $kpi->final_score,
                'items' => $kpi->items->mapWithKeys(fn ($item) => [$item->definition_code_snapshot => $item->actual_decimal])->all(),
            ],
        ])->all();
    }
}
