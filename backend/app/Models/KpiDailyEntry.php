<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiDailyEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_item_id',
        'entry_date',
        'entry_status',
        'supervisor_actual_decimal',
        'supervisor_actual_json',
        'supervisor_answers_json',
        'supervisor_score_percentage',
        'supervisor_note',
        'supervisor_assessed_by',
        'supervisor_status',
        'supervisor_assessed_at',
        'manager_actual_decimal',
        'manager_actual_json',
        'manager_answers_json',
        'manager_score_percentage',
        'manager_note',
        'manager_assessed_by',
        'manager_status',
        'manager_assessed_at',
        'row_version',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'employee_actual_decimal' => 'decimal:2',
        'employee_actual_json' => 'array',
        'employee_submitted_at' => 'datetime',
        'supervisor_actual_decimal' => 'decimal:2',
        'supervisor_actual_json' => 'array',
        'supervisor_answers_json' => 'array',
        'supervisor_score_percentage' => 'decimal:2',
        'supervisor_assessed_at' => 'datetime',
        'manager_actual_decimal' => 'decimal:2',
        'manager_actual_json' => 'array',
        'manager_answers_json' => 'array',
        'manager_score_percentage' => 'decimal:2',
        'manager_assessed_at' => 'datetime',
        'row_version' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(EmployeeKpiItem::class, 'employee_kpi_item_id');
    }

    public function employeeEntryUser()
    {
        return $this->belongsTo(User::class, 'employee_entered_by');
    }

    public function supervisorAssessor()
    {
        return $this->belongsTo(User::class, 'supervisor_assessed_by');
    }

    public function managerAssessor()
    {
        return $this->belongsTo(User::class, 'manager_assessed_by');
    }

    public function effectiveActualDecimal(): ?float
    {
        foreach ([$this->manager_actual_decimal, $this->supervisor_actual_decimal] as $value) {
            if ($value !== null) {
                return (float) $value;
            }
        }

        return null;
    }

    public function effectiveRubricScore(): ?float
    {
        foreach ([$this->manager_score_percentage, $this->supervisor_score_percentage] as $value) {
            if ($value !== null) {
                return (float) $value;
            }
        }

        return null;
    }
}
