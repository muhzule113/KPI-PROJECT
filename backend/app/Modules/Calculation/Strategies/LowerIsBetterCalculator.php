<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class LowerIsBetterCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        if ($item->actual_decimal === null) {
            return CalculationResult::pending('Nilai aktual belum diisi');
        }
        $weight = BigDecimal::of((string) $item->weight_snapshot);
        $target = BigDecimal::of((string) ($item->target_value_snapshot ?? 0));
        $actual = BigDecimal::of((string) $item->actual_decimal);

        $targetData = $item->target_json_snapshot ?? [];
        $formulaParams = $item->formula_params_snapshot ?? [];
        $failureValue = $targetData['failure_limit'] ?? $formulaParams['failure_limit'] ?? null;
        $failureLimit = $failureValue === null ? null : BigDecimal::of((string) $failureValue);

        if ($failureLimit === null || $failureLimit->isLessThanOrEqualTo($target)) {
            return CalculationResult::unscorable('Failure limit belum dikonfigurasi atau tidak lebih besar dari target');
        }

        $cap = BigDecimal::of((string) ($formulaParams['cap'] ?? 100));

        if ($actual->isLessThanOrEqualTo($target)) {
            $achievement = BigDecimal::of(100);
        } elseif ($actual->isGreaterThanOrEqualTo($failureLimit)) {
            $achievement = BigDecimal::zero();
        } else {
            $achievement = $failureLimit->minus($actual)->multipliedBy(100)
                ->dividedBy($failureLimit->minus($target), 6, RoundingMode::HalfUp);
        }

        $achievement = $achievement->isGreaterThan($cap) ? $cap : $achievement;
        $weightedScore = $achievement->multipliedBy($weight)->dividedBy(100, 6, RoundingMode::HalfUp);

        return CalculationResult::calculated($achievement->toFloat(), $weightedScore->toFloat(), [
            'target' => $target->toFloat(),
            'failure_limit' => $failureLimit->toFloat(),
            'actual' => $actual->toFloat(),
            'cap' => $cap->toFloat(),
            'weight' => $weight->toFloat(),
        ]);
    }
}
