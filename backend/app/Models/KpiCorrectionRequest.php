<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiCorrectionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_id',
        'requested_by',
        'approved_by',
        'reason',
        'before_json',
        'after_json',
        'status',
        'applied_at',
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
