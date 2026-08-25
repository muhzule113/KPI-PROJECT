<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;

class HigherIsBetterCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        $weight = (float) $item->weight_snapshot;
        $target = (float) ($item->target_value_snapshot ?? 0);
        $actual = $item->actual_decimal !== null ? (float) $item->actual_decimal : null;

        if ($actual === null) {
            return CalculationResult::pending('Nilai aktual belum diisi');
        }

        if ($target <= 0) {
            return CalculationResult::unscorable('Target bernilai 0 atau tidak valid untuk formula higher_is_better');
        }

        $params = $item->formula_params_snapshot ?? [];
        $cap = isset($params['cap']) ? (float) $params['cap'] : 100.0;

        $rawAchievement = ($actual / $target) * 100.0;
        $achievement = min($rawAchievement, $cap);
        $weightedScore = ($achievement * ($weight / 100.0));

        return CalculationResult::calculated($achievement, $weightedScore, [
            'raw_achievement' => round($rawAchievement, 4),
            'cap' => $cap,
            'target' => $target,
            'actual' => $actual,
            'weight' => $weight,
        ]);
    }
}
