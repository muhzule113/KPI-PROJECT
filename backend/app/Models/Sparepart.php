<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sparepart extends Model
{
    use HasFactory;

    public const TYPE_SPAREPART = 'sparepart';
    public const TYPE_HANDSET = 'handset';
    public const TYPE_TABLET = 'tablet';
    public const TYPE_ACCESSORY = 'aksesoris';
    public const TYPE_OTHER = 'lainnya';

    public const TYPES = [
        self::TYPE_SPAREPART => 'Sparepart',
        self::TYPE_HANDSET => 'Handset HP',
        self::TYPE_TABLET => 'Tablet / iPad',
        self::TYPE_ACCESSORY => 'Aksesoris',
        self::TYPE_OTHER => 'Lainnya',
    ];

    protected $fillable = [
        'code',
        'product_type',
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

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function opnameItems(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }
}
