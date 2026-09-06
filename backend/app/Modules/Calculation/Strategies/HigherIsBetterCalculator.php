<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class HigherIsBetterCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        if ($item->actual_decimal === null) {
            return CalculationResult::pending('Nilai aktual belum diisi');
        }
        $weight = BigDecimal::of((string) $item->weight_snapshot);
        $target = BigDecimal::of((string) ($item->target_value_snapshot ?? 0));
        $actual = BigDecimal::of((string) $item->actual_decimal);

        if ($target->isLessThanOrEqualTo(0)) {
            return CalculationResult::unscorable('Target bernilai 0 atau tidak valid untuk formula higher_is_better');
        }

        $params = $item->formula_params_snapshot ?? [];
        $cap = BigDecimal::of((string) ($params['cap'] ?? 100));

        $rawAchievement = $actual->multipliedBy(100)->dividedBy($target, 6, RoundingMode::HalfUp);
        $achievement = $rawAchievement->isGreaterThan($cap) ? $cap : $rawAchievement;
        $weightedScore = $achievement->multipliedBy($weight)
            ->dividedBy(100, 6, RoundingMode::HalfUp);

        return CalculationResult::calculated($achievement->toFloat(), $weightedScore->toFloat(), [
            'raw_achievement' => $rawAchievement->toFloat(),
            'cap' => $cap->toFloat(),
            'target' => $target->toFloat(),
            'actual' => $actual->toFloat(),
            'weight' => $weight->toFloat(),
        ]);
    }
}
