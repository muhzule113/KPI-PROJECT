<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;

class LowerIsBetterCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        $weight = (float) $item->weight_snapshot;
        $target = (float) ($item->target_value_snapshot ?? 0);
        $actual = $item->actual_decimal !== null ? (float) $item->actual_decimal : null;

        if ($actual === null) {
            return CalculationResult::pending('Nilai aktual belum diisi');
        }

        $targetData = $item->target_json_snapshot ?? [];
        $formulaParams = $item->formula_params_snapshot ?? [];
        $failureLimit = isset($targetData['failure_limit']) 
            ? (float) $targetData['failure_limit'] 
            : (isset($formulaParams['failure_limit']) ? (float) $formulaParams['failure_limit'] : null);

        if ($failureLimit === null || $failureLimit <= $target) {
            return CalculationResult::unscorable('Failure limit belum dikonfigurasi atau tidak lebih besar dari target');
        }

        $cap = isset($formulaParams['cap']) ? (float) $formulaParams['cap'] : 100.0;

        if ($actual <= $target) {
            $achievement = 100.0;
        } elseif ($actual >= $failureLimit) {
            $achievement = 0.0;
        } else {
            $achievement = (($failureLimit - $actual) / ($failureLimit - $target)) * 100.0;
        }

        $achievement = min(max($achievement, 0.0), $cap);
        $weightedScore = ($achievement * ($weight / 100.0));

        return CalculationResult::calculated($achievement, $weightedScore, [
            'target' => $target,
            'failure_limit' => $failureLimit,
            'actual' => $actual,
            'cap' => $cap,
            'weight' => $weight,
        ]);
    }
}
