<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'metric_type',
        'unit',
        'direction',
        'default_formula',
        'source_type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function templateItems()
    {
        return $this->hasMany(KpiTemplateItem::class);
    }
}
