<?php

namespace Tests\Unit;

use App\Models\EmployeeKpiItem;
use App\Models\KpiAssessment;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Modules\Calculation\Strategies\HigherIsBetterCalculator;
use App\Modules\Calculation\Strategies\LowerIsBetterCalculator;
use App\Modules\Calculation\Strategies\RubricCalculator;
use App\Modules\Calculation\Strategies\ZeroToleranceCalculator;
use Tests\TestCase;

class KpiCalculationEngineTest extends TestCase
{
    protected HigherIsBetterCalculator $higherCalc;
    protected LowerIsBetterCalculator $lowerCalc;
    protected ZeroToleranceCalculator $zeroCalc;
    protected RubricCalculator $rubricCalc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->higherCalc = new HigherIsBetterCalculator();
        $this->lowerCalc = new LowerIsBetterCalculator();
        $this->zeroCalc = new ZeroToleranceCalculator();
        $this->rubricCalc = new RubricCalculator();
    }

    public function test_higher_is_better_normal_and_capped(): void
    {
        $item = new EmployeeKpiItem([
            'weight_snapshot' => 25.00,
            'target_value_snapshot' => 80.00,
            'actual_decimal' => 80.00,
            'formula_params_snapshot' => ['cap' => 100.0],
        ]);

        $res = $this->higherCalc->calculate($item);
        $this->assertTrue($res->isSuccess);
        $this->assertEquals(100.0, $res->achievementPercentage);
        $this->assertEquals(25.0, $res->weightedScore);

        // Overachievement capped at 100%
        $item->actual_decimal = 100.00;
        $resOver = $this->higherCalc->calculate($item);
        $this->assertEquals(100.0, $resOver->achievementPercentage);
        $this->assertEquals(25.0, $resOver->weightedScore);

        // Underachievement
        $item->actual_decimal = 40.00;
        $resUnder = $this->higherCalc->calculate($item);
        $this->assertEquals(50.0, $resUnder->achievementPercentage);
        $this->assertEquals(12.5, $resUnder->weightedScore);
    }

    public function test_lower_is_better_threshold_and_failure_limit(): void
    {
        // TEK-03: target <= 3%, failure_limit = 6%, weight = 15%
        $item = new EmployeeKpiItem([
            'weight_snapshot' => 15.00,
            'target_value_snapshot' => 3.00,
            'target_json_snapshot' => ['failure_limit' => 6.00],
            'actual_decimal' => 2.00, // Below target is good -> 100%
        ]);

        $resGood = $this->lowerCalc->calculate($item);
        $this->assertEquals(100.0, $resGood->achievementPercentage);
        $this->assertEquals(15.0, $resGood->weightedScore);

        // At failure limit -> 0%
        $item->actual_decimal = 6.00;
        $resFail = $this->lowerCalc->calculate($item);
        $this->assertEquals(0.0, $resFail->achievementPercentage);
        $this->assertEquals(0.0, $resFail->weightedScore);

        // In between: 4% actual -> ((6 - 4) / (6 - 3)) * 100 = 66.67%
        $item->actual_decimal = 4.00;
        $resMid = $this->lowerCalc->calculate($item);
        $this->assertEquals(66.6667, round($resMid->achievementPercentage, 4));
        $this->assertEquals(10.0, round($resMid->weightedScore, 1));
    }

    public function test_zero_tolerance_cash_difference(): void
    {
        // KSR-02: full_score_limit = 50,000 IDR, failure_limit = 200,000 IDR, weight = 25%
        $item = new EmployeeKpiItem([
            'weight_snapshot' => 25.00,
            'target_json_snapshot' => [
                'full_score_limit' => 50000.00,
                'failure_limit' => 200000.00,
            ],
            'actual_decimal' => 25000.00, // <= 50,000 -> 100%
        ]);

        $resFull = $this->zeroCalc->calculate($item);
        $this->assertEquals(100.0, $resFull->achievementPercentage);
        $this->assertEquals(25.0, $resFull->weightedScore);

        // >= 200,000 -> 0%
        $item->actual_decimal = 250000.00;
        $resZero = $this->zeroCalc->calculate($item);
        $this->assertEquals(0.0, $resZero->achievementPercentage);
        $this->assertEquals(0.0, $resZero->weightedScore);

        // Mid difference 125,000 -> ((200k - 125k) / (200k - 50k)) * 100 = 50%
        $item->actual_decimal = 125000.00;
        $resMid = $this->zeroCalc->calculate($item);
        $this->assertEquals(50.0, $resMid->achievementPercentage);
        $this->assertEquals(12.5, $resMid->weightedScore);
    }

    public function test_rubric_calculator(): void
    {
        $item = new EmployeeKpiItem([
            'weight_snapshot' => 10.00,
            'formula_key_snapshot' => 'rubric',
        ]);

        $assessment = new KpiAssessment([
            'total_points' => 5.0,
            'score_points' => 4.0, // 4 out of 5 -> 80%
        ]);
        $item->setRelation('assessment', $assessment);

        $res = $this->rubricCalc->calculate($item);
        $this->assertEquals(80.0, $res->achievementPercentage);
        $this->assertEquals(8.0, $res->weightedScore);
    }

    public function test_zero_target_division_guard(): void
    {
        $item = new EmployeeKpiItem([
            'weight_snapshot' => 20.00,
            'target_value_snapshot' => 0.00, // Invalid target
            'actual_decimal' => 10.00,
        ]);

        $res = $this->higherCalc->calculate($item);
        $this->assertFalse($res->isSuccess);
        $this->assertEquals('unscorable', $res->status);
    }
}
