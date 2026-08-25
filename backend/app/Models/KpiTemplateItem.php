<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_version_id',
        'kpi_definition_id',
        'weight',
        'target_value',
        'target_unit',
        'target_json',
        'formula_key',
        'formula_params',
        'source_type',
        'evidence_required',
        'is_mandatory',
        'sort_order',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'target_value' => 'decimal:2',
        'target_json' => 'array',
        'formula_params' => 'array',
        'evidence_required' => 'boolean',
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function version()
    {
        return $this->belongsTo(KpiTemplateVersion::class, 'template_version_id');
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function rubric()
    {
        return $this->hasOne(KpiRubric::class, 'template_item_id');
    }
}
