<?php

namespace App\Modules\Reporting;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;

final class RankingService
{
    public function forPeriod(KpiPeriod $period): array
    {
        return EmployeeKpi::with(['items', 'correctionRequests'])
            ->where('period_id', $period->id)
            ->where('status', 'locked')
            ->where('eligibility', 'full')
            ->whereNotNull('final_score')->get()
            ->groupBy('position_code_snapshot')
            ->map(function ($rows, string $position): array {
                $sorted = $rows->map(function (EmployeeKpi $kpi): array {
                    $highestWeight = $kpi->items->sortByDesc('weight_snapshot')->first();

                    return [
                        'kpi_id' => $kpi->id,
                        'employee_number' => $kpi->employee_number_snapshot,
                        'employee_name' => $kpi->employee_name_snapshot,
                        'position_code' => $kpi->position_code_snapshot,
                        'final_score' => (float) $kpi->final_score,
                        'tie_break_achievement' => (float) ($highestWeight?->achievement_percentage ?? 0),
                        'correction_in_progress' => $kpi->correctionRequests->contains('status', 'pending'),
                    ];
                })->sort(fn (array $left, array $right): int => [$right['final_score'], $right['tie_break_achievement']] <=> [$left['final_score'], $left['tie_break_achievement']]
                )->values();
                $rank = 0;
                $previous = null;

                return $sorted->map(function (array $row, int $index) use (&$rank, &$previous): array {
                    $key = $row['final_score'].'|'.$row['tie_break_achievement'];
                    if ($key !== $previous) {
                        $rank = $index + 1;
                        $previous = $key;
                    }

                    return ['rank' => $rank, ...$row];
                })->all();
            })->all();
    }
}
