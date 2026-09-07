<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $opname): void {
            $opname->created_by ??= auth()->id();
            if (! $opname->creator?->hasRole('super_admin')) {
                $opname->branch_id = $opname->creator?->employee?->branch_id;
            }
            if (! $opname->branch_id || ! $opname->period?->branches()->whereKey($opname->branch_id)->exists()) {
                throw new \RuntimeException('Cabang pembuat opname harus terdaftar pada periode KPI.');
            }
        });
        static::updating(function (self $opname): void {
            if ($opname->isDirty(['branch_id', 'period_id', 'created_by'])) {
                throw new \RuntimeException('Cabang, periode, dan penanggung jawab opname tidak dapat diganti.');
            }
        });
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'code',
        'period_id',
        'branch_id',
        'status',
        'deadline',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'deadline' => 'date',
        'completed_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function countedItems(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class)->where('is_counted', true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_IN_PROGRESS => 'Sedang Berjalan',
            self::STATUS_COMPLETED => 'Selesai',
            default => ucfirst($status),
        };
    }
}
