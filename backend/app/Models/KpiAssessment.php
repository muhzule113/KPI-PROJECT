<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_item_id',
        'kpi_review_id',
        'assessed_by',
        'score_points',
        'total_points',
        'calculated_achievement',
    ];

    protected $casts = [
        'score_points' => 'decimal:2',
        'total_points' => 'decimal:2',
        'calculated_achievement' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(EmployeeKpiItem::class, 'employee_kpi_item_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function answers()
    {
        return $this->hasMany(KpiAssessmentAnswer::class, 'kpi_assessment_id');
    }
}
