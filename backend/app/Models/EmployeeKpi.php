<?php

namespace App\Models;

use App\Events\KpiDataUpdated;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeKpi extends Model
{
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::creating(function (self $kpi): void {
            $employee = $kpi->employee;
            $kpi->branch_id_snapshot ??= $employee?->branch_id;
            $kpi->position_id_snapshot ??= $employee?->position_id;
            $kpi->position_code_snapshot ??= $employee?->position?->code;
        });
        static::saved(function (self $kpi): void {
            if ($kpi->period_id !== null && $kpi->wasChanged()) {
                KpiDataUpdated::dispatch((int) $kpi->period_id);
            }
        });
    }

    protected $fillable = [
        'period_id',
        'employee_id',
        'employee_number_snapshot',
        'employee_name_snapshot',
        'template_version_id',
        'supervisor_id_snapshot',
        'manager_id_snapshot',
        'branch_id_snapshot',
        'position_id_snapshot',
        'position_code_snapshot',
        'placement_id_snapshot',
        'eligibility',
        'score_cap_snapshot',
        'rating_bands_snapshot',
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
        'score_cap_snapshot' => 'decimal:6',
        'rating_bands_snapshot' => 'array',
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

    public function branchSnapshot()
    {
        return $this->belongsTo(Branch::class, 'branch_id_snapshot');
    }

    public function positionSnapshot()
    {
        return $this->belongsTo(Position::class, 'position_id_snapshot');
    }

    public function items()
    {
        return $this->hasMany(EmployeeKpiItem::class, 'employee_kpi_id');
    }

    public function isSupervisorKpi(): bool
    {
        return ($this->position_code_snapshot ?? $this->employee?->position?->code) === 'POS-SPV';
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
        if ($totalItems === 0) {
            return 0.0;
        }

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
