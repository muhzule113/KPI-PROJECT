<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sparepart extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'compatible_models',
        'stock_quantity',
        'min_stock_alert',
        'purchase_price',
        'selling_price',
        'is_critical',
    ];

    protected $casts = [
        'stock_quantity' => 'integer',
        'min_stock_alert' => 'integer',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_critical' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(SparepartRequest::class);
    }
}
