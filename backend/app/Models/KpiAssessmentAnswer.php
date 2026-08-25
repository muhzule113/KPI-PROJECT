<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiAssessmentAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_assessment_id',
        'criterion_id',
        'criterion_text',
        'is_fulfilled',
        'points_earned',
        'notes',
    ];

    protected $casts = [
        'is_fulfilled' => 'boolean',
        'points_earned' => 'decimal:2',
    ];

    public function assessment()
    {
        return $this->belongsTo(KpiAssessment::class, 'kpi_assessment_id');
    }
}
