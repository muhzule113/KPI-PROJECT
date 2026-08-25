<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRubricCriterion extends Model
{
    use HasFactory;

    protected $fillable = [
        'rubric_id',
        'criterion_text',
        'points',
        'is_mandatory',
        'sort_order',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rubric()
    {
        return $this->belongsTo(KpiRubric::class, 'rubric_id');
    }
}
