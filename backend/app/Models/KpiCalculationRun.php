<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiCalculationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_id',
        'run_type',
        'input_snapshot',
        'output_snapshot',
        'total_score',
        'rating_code',
        'calculated_by',
        'calculated_at',
    ];

    protected $casts = [
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'total_score' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function calculator()
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
