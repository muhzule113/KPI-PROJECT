<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeKpi extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'period_id',
        'employee_id',
        'template_version_id',
        'supervisor_id_snapshot',
        'manager_id_snapshot',
        'status',
        'progress_percentage',
        'final_score',
        'rating_code',
        'rating_label',
        'revision_number',
        'row_version',
        'submitted_at',
        'verified_at',
        'approved_at',
        'locked_at',
    ];

    protected $casts = [
        'progress_percentage' => 'decimal:2',
        'final_score' => 'decimal:2',
        'revision_number' => 'integer',
        'row_version' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(KpiPeriod::class, 'period_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function templateVersion()
    {
        return $this->belongsTo(KpiTemplateVersion::class, 'template_version_id');
    }

    public function supervisorSnapshot()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id_snapshot');
    }

    public function managerSnapshot()
    {
        return $this->belongsTo(Employee::class, 'manager_id_snapshot');
    }

    public function items()
    {
        return $this->hasMany(EmployeeKpiItem::class, 'employee_kpi_id');
    }

    public function reviews()
    {
        return $this->hasMany(KpiReview::class, 'employee_kpi_id')->orderByDesc('id');
    }

    public function approvals()
    {
        return $this->hasMany(KpiApproval::class, 'employee_kpi_id')->orderByDesc('id');
    }

    public function calculationRuns()
    {
        return $this->hasMany(KpiCalculationRun::class, 'employee_kpi_id')->orderByDesc('id');
    }

    public function correctionRequests()
    {
        return $this->hasMany(KpiCorrectionRequest::class, 'employee_kpi_id')->orderByDesc('id');
    }

    public function calculateProgress(): float
    {
        $totalItems = $this->items()->count();
        if ($totalItems === 0) return 0.0;
        
        $filledItems = $this->items()->where(function ($q) {
            $q->whereNotNull('actual_decimal')
              ->orWhereNotNull('actual_json')
              ->orWhere('status', 'verified')
              ->orWhere('status', 'assessed');
        })->count();

        $percentage = round(($filledItems / $totalItems) * 100, 2);
        $this->update(['progress_percentage' => $percentage]);
        return $percentage;
    }
}
