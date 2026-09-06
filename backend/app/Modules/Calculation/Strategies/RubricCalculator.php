<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class RubricCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        $weight = (float) $item->weight_snapshot;

        if (is_array($item->actual_json) && ! empty($item->actual_json['_daily_aggregate']) && $item->actual_decimal !== null) {
            $achievement = min(max((float) $item->actual_decimal, 0.0), 100.0);
            $weighted = BigDecimal::of((string) $achievement)->multipliedBy(BigDecimal::of((string) $weight))
                ->dividedBy(100, 6, RoundingMode::HalfUp)->toFloat();

            return CalculationResult::calculated($achievement, $weighted, [
                'source' => 'daily_aggregate',
                'weight' => $weight,
            ]);
        }

        // If the item has an assessment record from supervisor review
        $assessment = $item->assessment;
        if (! $assessment) {
            // Check if actual_decimal is already provided directly
            if ($item->actual_decimal !== null) {
                $achievement = min(max((float) $item->actual_decimal, 0.0), 100.0);
                $weightedScore = BigDecimal::of((string) $achievement)->multipliedBy(BigDecimal::of((string) $weight))
                    ->dividedBy(100, 6, RoundingMode::HalfUp)->toFloat();

                return CalculationResult::calculated($achievement, $weightedScore, [
                    'source' => 'direct_actual',
                    'weight' => $weight,
                ]);
            }

            return CalculationResult::pending('Checklist observasi / rubric belum dinilai oleh Supervisor');
        }

        $totalPoints = (float) $assessment->total_points;
        $scorePoints = (float) $assessment->score_points;

        if ($totalPoints <= 0) {
            return CalculationResult::unscorable('Total poin kriteria rubrik tidak valid (0)');
        }

        $rawAchievement = BigDecimal::of((string) $scorePoints)->multipliedBy(100)
            ->dividedBy(BigDecimal::of((string) $totalPoints), 6, RoundingMode::HalfUp)->toFloat();
        $achievement = min($rawAchievement, 100.0);
        $weightedScore = BigDecimal::of((string) $achievement)->multipliedBy(BigDecimal::of((string) $weight))
            ->dividedBy(100, 6, RoundingMode::HalfUp)->toFloat();

        return CalculationResult::calculated($achievement, $weightedScore, [
            'score_points' => $scorePoints,
            'total_points' => $totalPoints,
            'weight' => $weight,
        ]);
    }
}
