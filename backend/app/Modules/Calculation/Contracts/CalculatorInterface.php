<?php

namespace App\Modules\Calculation\Contracts;

use App\Models\EmployeeKpiItem;
use App\Modules\Calculation\CalculationResult;

interface CalculatorInterface
{
    public function calculate(EmployeeKpiItem $item): CalculationResult;
}
