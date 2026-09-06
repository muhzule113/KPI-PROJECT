<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ZeroToleranceCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        if ($item->actual_decimal === null) {
            return CalculationResult::pending('Nilai aktual selisih kas belum diisi/di-import');
        }
        $weight = BigDecimal::of((string) $item->weight_snapshot);
        $actual = BigDecimal::of((string) $item->actual_decimal);

        $targetData = $item->target_json_snapshot ?? [];
        $formulaParams = $item->formula_params_snapshot ?? [];

        $fullValue = $targetData['full_score_limit'] ?? $formulaParams['full_score_limit'] ?? null;
        $failureValue = $targetData['failure_limit'] ?? $formulaParams['failure_limit'] ?? null;
        $fullScoreLimit = $fullValue === null ? null : BigDecimal::of((string) $fullValue);
        $failureLimit = $failureValue === null ? null : BigDecimal::of((string) $failureValue);

        if ($fullScoreLimit === null || $failureLimit === null) {
            return CalculationResult::unscorable('Parameter full score limit dan failure limit wajib dikonfigurasi');
        }

        if ($failureLimit->isLessThanOrEqualTo($fullScoreLimit)) {
            return CalculationResult::unscorable('Failure limit harus lebih besar dari full score limit');
        }

        $cap = BigDecimal::of((string) ($formulaParams['cap'] ?? 100));
        $absActual = $actual->isNegative() ? $actual->negated() : $actual;

        if ($absActual->isLessThanOrEqualTo($fullScoreLimit)) {
            $achievement = BigDecimal::of(100);
        } elseif ($absActual->isGreaterThanOrEqualTo($failureLimit)) {
            $achievement = BigDecimal::zero();
        } else {
            $achievement = $failureLimit->minus($absActual)->multipliedBy(100)
                ->dividedBy($failureLimit->minus($fullScoreLimit), 6, RoundingMode::HalfUp);
        }

        $achievement = $achievement->isGreaterThan($cap) ? $cap : $achievement;
        $weightedScore = $achievement->multipliedBy($weight)->dividedBy(100, 6, RoundingMode::HalfUp);

        return CalculationResult::calculated($achievement->toFloat(), $weightedScore->toFloat(), [
            'full_score_limit' => $fullScoreLimit->toFloat(),
            'failure_limit' => $failureLimit->toFloat(),
            'abs_actual' => $absActual->toFloat(),
            'cap' => $cap->toFloat(),
            'weight' => $weight->toFloat(),
        ]);
    }
}
