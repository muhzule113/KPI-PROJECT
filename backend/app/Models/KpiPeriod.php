<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'month',
        'start_date',
        'end_date',
        'submission_deadline',
        'review_deadline',
        'approval_deadline',
        'status',
        'total_eligible_employees',
        'created_by',
        'opened_at',
        'closed_at',
        'published_at',
        'locked_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'submission_deadline' => 'datetime',
        'review_deadline' => 'datetime',
        'approval_deadline' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'published_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'kpi_period_branches', 'period_id', 'branch_id');
    }

    public function employeeKpis()
    {
        return $this->hasMany(EmployeeKpi::class, 'period_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    public function isLocked(): bool
    {
        return $this->status === 'LOCKED';
    }
}
