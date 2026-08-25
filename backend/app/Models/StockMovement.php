<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    public const TYPE_RESTOCK_IN = 'restock_in';
    public const TYPE_REQUEST_OUT = 'request_out';
    public const TYPE_OPNAME_ADJUSTMENT = 'opname_adjustment';
    public const TYPE_RETURN_IN = 'return_in';

    protected $fillable = [
        'sparepart_id',
        'movement_type',
        'quantity',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'note',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    public function sparepart(): BelongsTo
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_RESTOCK_IN => 'Restok Masuk',
            self::TYPE_REQUEST_OUT => 'Keluar (Request Teknisi)',
            self::TYPE_OPNAME_ADJUSTMENT => 'Penyesuaian Opname',
            self::TYPE_RETURN_IN => 'Retur Masuk',
            default => ucfirst($type),
        };
    }
}
