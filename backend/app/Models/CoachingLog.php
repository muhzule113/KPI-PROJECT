<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'supervisor_id',
        'employee_id',
        'coaching_date',
        'topic',
        'notes',
        'target_met',
        'follow_up_date',
        'period_id',
        'recorded_by',
    ];

    protected $casts = [
        'coaching_date' => 'date',
        'target_met' => 'boolean',
        'follow_up_date' => 'date',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
