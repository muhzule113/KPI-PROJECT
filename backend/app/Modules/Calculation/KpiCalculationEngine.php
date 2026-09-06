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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class KpiCalculationEngine
{
    /** @var array<string, CalculatorInterface> */
    protected array $strategies;

    public function __construct()
    {
        $this->strategies = [
            'higher_is_better' => new HigherIsBetterCalculator,
            'lower_is_better' => new LowerIsBetterCalculator,
            'zero_tolerance' => new ZeroToleranceCalculator,
            'rubric' => new RubricCalculator,
        ];
    }

    public function getCalculator(string $formulaKey): ?CalculatorInterface
    {
        return $this->strategies[$formulaKey] ?? null;
    }

    public function calculateItem(EmployeeKpiItem $item, bool $persist = true): CalculationResult
    {
        $calculator = $item->isManualRated()
            ? $this->strategies['rubric']
            : $this->getCalculator($item->formula_key_snapshot);
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

        $totalScoreRaw = BigDecimal::zero();
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
                $totalScoreRaw = $totalScoreRaw->plus(BigDecimal::of((string) $result->weightedScore));
            } else {
                $allCalculated = false;
            }
        }

        // Partial KPI tidak boleh terlihat sebagai skor final.
        $finalScore = $allCalculated
            ? ($totalScoreRaw->isGreaterThan(BigDecimal::of((string) ($kpi->score_cap_snapshot ?? 100)))
                ? BigDecimal::of((string) ($kpi->score_cap_snapshot ?? 100))
                : $totalScoreRaw)->toScale(2, RoundingMode::HalfUp)->toFloat()
            : null;

        // Snapshot selalu menang; fallback hanya untuk data legacy.
        $snapshotBand = $finalScore === null ? null : collect($kpi->rating_bands_snapshot ?? [])->first(
            fn (array $candidate): bool => $finalScore >= (float) $candidate['min_score'] && $finalScore <= (float) $candidate['max_score']
        );
        $ratingScheme = $snapshotBand ? null : ($kpi->templateVersion?->ratingScheme ?? KpiRatingScheme::where('is_default', true)->first());
        $band = null;
        if (! $snapshotBand && $ratingScheme && $finalScore !== null) {
            $band = KpiRatingBand::where('rating_scheme_id', $ratingScheme->id)
                ->where('min_score', '<=', $finalScore)
                ->where('max_score', '>=', $finalScore)
                ->first();
        }

        $kpi->final_score = $finalScore;
        $kpi->rating_code = $snapshotBand['code'] ?? $band?->code ?? ($finalScore >= 95 ? 'STAR' : ($finalScore >= 80 ? 'GOOD' : 'FAIR'));
        $kpi->rating_label = $snapshotBand['label'] ?? $band?->label ?? ($finalScore >= 95 ? '⭐ Istimewa' : ($finalScore >= 80 ? 'Baik' : 'Cukup'));
        if (! $allCalculated) {
            $kpi->rating_code = null;
            $kpi->rating_label = null;
        }
        $kpi->calculateProgress();
        $kpi->save();

        // Save Calculation Run Log for auditability
        $run = [
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
        ];
        $latest = in_array($runType, ['operational_sync', 'daily_aggregation'], true)
            ? $kpi->calculationRuns()->first() : null;
        if (! $latest || $latest->input_snapshot != $run['input_snapshot'] || $latest->output_snapshot != $run['output_snapshot']) {
            KpiCalculationRun::create($run);
        }

        return [
            'total_score' => $finalScore,
            'rating_code' => $kpi->rating_code,
            'rating_label' => $kpi->rating_label,
            'all_calculated' => $allCalculated,
            'items' => $itemsSnapshot,
        ];
    }
}
