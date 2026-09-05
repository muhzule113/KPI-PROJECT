<?php

namespace App\Modules\Calculation\Strategies;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;
use App\Modules\Calculation\Contracts\CalculatorInterface;

class ZeroToleranceCalculator implements CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult
    {
        $weight = (float) $item->weight_snapshot;
        $actual = $item->actual_decimal !== null ? (float) $item->actual_decimal : null;

        if ($actual === null) {
            return CalculationResult::pending('Nilai aktual selisih kas belum diisi/di-import');
        }

        $targetData = $item->target_json_snapshot ?? [];
        $formulaParams = $item->formula_params_snapshot ?? [];

        $fullScoreLimit = array_key_exists('full_score_limit', $targetData)
            ? (float) $targetData['full_score_limit']
            : (array_key_exists('full_score_limit', $formulaParams) ? (float) $formulaParams['full_score_limit'] : null);

        $failureLimit = array_key_exists('failure_limit', $targetData)
            ? (float) $targetData['failure_limit']
            : (array_key_exists('failure_limit', $formulaParams) ? (float) $formulaParams['failure_limit'] : null);

        if ($fullScoreLimit === null || $failureLimit === null) {
            return CalculationResult::unscorable('Parameter full score limit dan failure limit wajib dikonfigurasi');
        }

        if ($failureLimit <= $fullScoreLimit) {
            return CalculationResult::unscorable('Failure limit harus lebih besar dari full score limit');
        }

        $cap = isset($formulaParams['cap']) ? (float) $formulaParams['cap'] : 100.0;
        $absActual = abs($actual);

        if ($absActual <= $fullScoreLimit) {
            $achievement = 100.0;
        } elseif ($absActual >= $failureLimit) {
            $achievement = 0.0;
        } else {
            $achievement = (($failureLimit - $absActual) / ($failureLimit - $fullScoreLimit)) * 100.0;
        }

        $achievement = min(max($achievement, 0.0), $cap);
        $weightedScore = ($achievement * ($weight / 100.0));

        return CalculationResult::calculated($achievement, $weightedScore, [
            'full_score_limit' => $fullScoreLimit,
            'failure_limit' => $failureLimit,
            'abs_actual' => $absActual,
            'cap' => $cap,
            'weight' => $weight,
        ]);
    }
}
