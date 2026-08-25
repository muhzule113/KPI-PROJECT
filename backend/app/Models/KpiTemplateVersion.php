<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_template_id',
        'version_number',
        'status',
        'total_weight',
        'rating_scheme_id',
        'checksum',
        'effective_from',
        'effective_until',
        'activated_by',
        'activated_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'total_weight' => 'decimal:2',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'activated_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(KpiTemplate::class, 'kpi_template_id');
    }

    public function items()
    {
        return $this->hasMany(KpiTemplateItem::class, 'template_version_id')->orderBy('sort_order');
    }

    public function ratingScheme()
    {
        return $this->belongsTo(KpiRatingScheme::class, 'rating_scheme_id');
    }

    public function activator()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function calculateTotalWeight(): float
    {
        return (float) $this->items()->sum('weight');
    }
}
