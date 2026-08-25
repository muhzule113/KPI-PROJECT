<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeKpiItem extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'employee_kpi_id',
        'kpi_definition_id',
        'definition_code_snapshot',
        'name_snapshot',
        'weight_snapshot',
        'target_value_snapshot',
        'target_unit_snapshot',
        'target_json_snapshot',
        'formula_key_snapshot',
        'formula_params_snapshot',
        'source_type_snapshot',
        'evidence_req_snapshot',
        'rubric_snapshot',
        'status',
        'actual_decimal',
        'actual_json',
        'achievement_percentage',
        'weighted_score',
        'calculation_status',
        'calculation_note',
        'row_version',
    ];

    protected $casts = [
        'weight_snapshot' => 'decimal:2',
        'target_value_snapshot' => 'decimal:2',
        'target_json_snapshot' => 'array',
        'formula_params_snapshot' => 'array',
        'evidence_req_snapshot' => 'boolean',
        'rubric_snapshot' => 'array',
        'actual_decimal' => 'decimal:2',
        'actual_json' => 'array',
        'achievement_percentage' => 'decimal:2',
        'weighted_score' => 'decimal:2',
        'row_version' => 'integer',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function actualEntries()
    {
        return $this->hasMany(KpiActualEntry::class, 'employee_kpi_item_id');
    }

    public function evidences()
    {
        return $this->hasMany(KpiEvidence::class, 'employee_kpi_item_id');
    }

    public function reviewItems()
    {
        return $this->hasMany(KpiReviewItem::class, 'employee_kpi_item_id');
    }

    public function assessment()
    {
        return $this->hasOne(KpiAssessment::class, 'employee_kpi_item_id');
    }
}
