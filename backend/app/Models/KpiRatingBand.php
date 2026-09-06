<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRatingBand extends Model
{
    use HasFactory;

    protected $fillable = [
        'rating_scheme_id',
        'code',
        'label',
        'min_score',
        'max_score',
        'manual_score',
        'color',
        'badge_icon',
        'sort_order',
    ];

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'manual_score' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function scheme()
    {
        return $this->belongsTo(KpiRatingScheme::class, 'rating_scheme_id');
    }
}
