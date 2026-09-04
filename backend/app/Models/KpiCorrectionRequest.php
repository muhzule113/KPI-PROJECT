<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiCorrectionRequest extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            $request->pending_unique_key = $request->status === 'pending'
                ? "kpi:{$request->employee_kpi_id}"
                : null;
        });
    }

    protected $fillable = [
        'employee_kpi_id',
        'requested_by',
        'approved_by',
        'reason',
        'before_json',
        'after_json',
        'status',
        'rejection_reason',
        'row_version_snapshot',
        'applied_at',
        'pending_unique_key',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'applied_at' => 'datetime',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
