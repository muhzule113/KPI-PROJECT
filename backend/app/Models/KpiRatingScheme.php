<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRatingScheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function bands()
    {
        return $this->hasMany(KpiRatingBand::class, 'rating_scheme_id')->orderBy('sort_order');
    }

    public function getBandForScore(float $score): ?KpiRatingBand
    {
        return $this->bands()
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->first();
    }
}
