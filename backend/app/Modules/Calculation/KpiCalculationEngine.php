<?php

namespace App\Modules\Calculation;

use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiCalculationRun;
use App\Models\KpiRatingBand;
use App\Models\KpiRatingScheme;
use App\Modules\Calculation\Contracts\CalculatorInterface;
use App\Modules\Calculation\Strategies\HigherIsBetterCalculator;
use App\Modules\Calculation\Strategies\LowerIsBetterCalculator;
use App\Modules\Calculation\Strategies\RubricCalculator;
use App\Modules\Calculation\Strategies\ZeroToleranceCalculator;

class KpiCalculationEngine
{
    /** @var array<string, CalculatorInterface> */
    protected array $strategies;

    public function __construct()
    {
        $this->strategies = [
            'higher_is_better' => new HigherIsBetterCalculator(),
            'lower_is_better' => new LowerIsBetterCalculator(),
            'zero_tolerance' => new ZeroToleranceCalculator(),
            'rubric' => new RubricCalculator(),
        ];
    }

    public function getCalculator(string $formulaKey): ?CalculatorInterface
    {
        return $this->strategies[$formulaKey] ?? null;
    }

    public function calculateItem(EmployeeKpiItem $item, bool $persist = true): CalculationResult
    {
        $calculator = $this->getCalculator($item->formula_key_snapshot);
        $result = $calculator
            ? $calculator->calculate($item)
            : CalculationResult::unscorable("Formula KPI '{$item->formula_key_snapshot}' tidak dikenali.");

        if ($persist) {
            $item->achievement_percentage = $result->achievementPercentage;
            $item->weighted_score = $result->weightedScore;
            $item->calculation_status = $result->status;
            $item->calculation_note = $result->note;
            $item->save();
        }

        return $result;
    }

    public function calculateKpi(EmployeeKpi $kpi, string $runType = 'recalculation', ?int $userId = null): array
    {
        $kpi->loadMissing(['items.assessment', 'templateVersion.ratingScheme.bands']);

        $totalScoreRaw = 0.0;
        $allCalculated = true;
        $itemsSnapshot = [];

        foreach ($kpi->items as $item) {
            $result = $this->calculateItem($item, true);

            $itemsSnapshot[] = [
                'item_id' => $item->id,
                'code' => $item->definition_code_snapshot,
                'name' => $item->name_snapshot,
                'weight' => (float) $item->weight_snapshot,
                'actual' => $item->actual_decimal,
                'formula' => $item->formula_key_snapshot,
                'achievement' => $result->achievementPercentage,
                'weighted_score' => $result->weightedScore,
                'status' => $result->status,
                'note' => $result->note,
                'meta' => $result->meta,
            ];

            if ($result->status === 'calculated' && $result->weightedScore !== null) {
                $totalScoreRaw += (float) $result->weightedScore;
            } else {
                $allCalculated = false;
            }
        }

        // Partial KPI tidak boleh terlihat sebagai skor final.
        $finalScore = $allCalculated
            ? round($totalScoreRaw, 2, PHP_ROUND_HALF_UP)
            : null;

        // Find rating band
        $ratingScheme = $kpi->templateVersion?->ratingScheme ?? KpiRatingScheme::where('is_default', true)->first();
        $band = null;
        if ($ratingScheme && $finalScore !== null) {
            $band = KpiRatingBand::where('rating_scheme_id', $ratingScheme->id)
                ->where('min_score', '<=', $finalScore)
                ->where('max_score', '>=', $finalScore)
                ->first();
        }

        $kpi->final_score = $finalScore;
        $kpi->rating_code = $band?->code ?? ($finalScore >= 95 ? 'STAR' : ($finalScore >= 80 ? 'GOOD' : 'FAIR'));
        $kpi->rating_label = $band?->label ?? ($finalScore >= 95 ? '⭐ Istimewa' : ($finalScore >= 80 ? 'Baik' : 'Cukup'));
        if (!$allCalculated) {
            $kpi->rating_code = null;
            $kpi->rating_label = null;
        }
        $kpi->calculateProgress();
        $kpi->save();

        // Save Calculation Run Log for auditability
        KpiCalculationRun::create([
            'employee_kpi_id' => $kpi->id,
            'run_type' => $runType,
            'input_snapshot' => [
                'period_id' => $kpi->period_id,
                'employee_id' => $kpi->employee_id,
                'template_version_id' => $kpi->template_version_id,
                'revision_number' => $kpi->revision_number,
                'items' => collect($itemsSnapshot)->map(fn (array $item) => [
                    'item_id' => $item['item_id'],
                    'actual' => $item['actual'],
                    'formula' => $item['formula'],
                    'formula_params' => $kpi->items->firstWhere('id', $item['item_id'])?->formula_params_snapshot,
                    'target' => $kpi->items->firstWhere('id', $item['item_id'])?->target_value_snapshot,
                    'target_json' => $kpi->items->firstWhere('id', $item['item_id'])?->target_json_snapshot,
                ])->values()->all(),
            ],
            'output_snapshot' => [
                'total_score' => $finalScore,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'all_items_calculated' => $allCalculated,
                'items' => $itemsSnapshot,
            ],
            'total_score' => $finalScore,
            'rating_code' => $kpi->rating_code,
            'calculated_by' => $userId ?? auth()->id(),
            'calculated_at' => now(),
        ]);

        return [
            'total_score' => $finalScore,
            'rating_code' => $kpi->rating_code,
            'rating_label' => $kpi->rating_label,
            'all_calculated' => $allCalculated,
            'items' => $itemsSnapshot,
        ];
    }
}
