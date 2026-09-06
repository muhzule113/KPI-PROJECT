<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeKpiItem extends Model
{
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if (! $item->exists || (! $item->isSystemSourced() && ! $item->isAttendanceIndicator())) {
                return;
            }
            $sourceChanged = $item->isDirty(['actual_decimal', 'actual_json']);
            if (! $sourceChanged) {
                if ($item->status === 'draft' && in_array($item->getOriginal('status'), ['verified', 'assessed'], true)) {
                    $item->status = $item->getOriginal('status');
                }

                return;
            }
            $kpi = $item->employeeKpi()->first();
            if (! $kpi || in_array($kpi->status, ['approved', 'locked'], true)) {
                return;
            }
            $item->manager_decision = null;
            $item->manager_decided_at = null;
            $item->manager_decided_by = null;
            $item->status = 'draft';
            $item->row_version += 1;
            $item->dailyEntries()->where('system_actual_json->cadence', 'period')->update([
                'system_actual_decimal' => $item->actual_decimal,
                'supervisor_status' => 'pending',
                'manager_status' => 'pending',
                'supervisor_assessed_at' => null,
                'manager_assessed_at' => null,
                'supervisor_assessed_by' => null,
                'manager_assessed_by' => null,
                'supervisor_actual_decimal' => null,
                'manager_actual_decimal' => null,
            ]);
            if (in_array($kpi->status, ['verified', 'pending_approval'], true)) {
                $kpi->status = 'under_review';
                $kpi->verified_at = null;
            }
            $kpi->final_score = null;
            $kpi->rating_code = null;
            $kpi->rating_label = null;
            $kpi->row_version += 1;
            $kpi->save();
        });
    }

    protected $fillable = [
        'employee_kpi_id',
        'kpi_definition_id',
        'definition_code_snapshot',
        'name_snapshot',
        'weight_snapshot',
        'target_value_snapshot',
        'target_unit_snapshot',
        'target_json_snapshot',
        'formula_key_snapshot',
        'formula_params_snapshot',
        'source_type_snapshot',
        'evidence_req_snapshot',
        'rubric_snapshot',
        'status',
        'actual_decimal',
        'actual_json',
        'achievement_percentage',
        'weighted_score',
        'calculation_status',
        'calculation_note',
        'manager_decision',
        'manager_note',
        'manager_evidence_json',
        'manager_decided_by',
        'manager_decided_at',
        'row_version',
    ];

    protected $casts = [
        'weight_snapshot' => 'decimal:2',
        'target_value_snapshot' => 'decimal:2',
        'target_json_snapshot' => 'array',
        'formula_params_snapshot' => 'array',
        'evidence_req_snapshot' => 'boolean',
        'rubric_snapshot' => 'array',
        'actual_decimal' => 'decimal:6',
        'actual_json' => 'array',
        'achievement_percentage' => 'decimal:6',
        'weighted_score' => 'decimal:6',
        'manager_evidence_json' => 'array',
        'manager_decided_at' => 'datetime',
        'row_version' => 'integer',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function actualEntries()
    {
        return $this->hasMany(KpiActualEntry::class, 'employee_kpi_item_id');
    }

    public function dailyEntries()
    {
        return $this->hasMany(KpiDailyEntry::class, 'employee_kpi_item_id')->orderBy('entry_date');
    }

    public function evidences()
    {
        return $this->hasMany(KpiEvidence::class, 'employee_kpi_item_id');
    }

    public function reviewItems()
    {
        return $this->hasMany(KpiReviewItem::class, 'employee_kpi_item_id');
    }

    public function assessment()
    {
        return $this->hasOne(KpiAssessment::class, 'employee_kpi_item_id');
    }

    public function isSystemSourced(): bool
    {
        return in_array(strtolower((string) $this->source_type_snapshot), ['system', 'cross_role', 'import'], true);
    }

    public function cadence(): string
    {
        return $this->formula_params_snapshot['cadence'] ?? ($this->isSystemSourced() ? 'period' : 'daily');
    }

    public function isAttendanceIndicator(): bool
    {
        return in_array($this->definition_code_snapshot, ['ADM-05', 'KSR-06', 'GUD-07', 'CS-06'], true);
    }

    public function isManualRated(): bool
    {
        return strtolower((string) $this->source_type_snapshot) === 'supervisor'
            && ! $this->isAttendanceIndicator();
    }

    public function manualRatingOptions(): array
    {
        return $this->rubric_snapshot['manual_rating_options'] ?? [
            ['code' => 'FAIR', 'label' => 'Cukup', 'score' => 75.00],
            ['code' => 'GOOD', 'label' => 'Baik', 'score' => 85.00],
            ['code' => 'VERY_GOOD', 'label' => 'Sangat Baik', 'score' => 95.00],
        ];
    }

    public function manualRating(string $code): ?array
    {
        return collect($this->manualRatingOptions())
            ->first(fn (array $option): bool => (string) ($option['code'] ?? '') === $code);
    }

    /**
     * System feeds may provide a provisional value for employee-owned facts,
     * but must stop once a human value or a daily aggregate has been recorded.
     */
    public function acceptsSystemCalculatedValue(): bool
    {
        if ($this->isSystemSourced()) {
            return true;
        }

        if ($this->actual_decimal === null && $this->actual_json === null) {
            return true;
        }

        return is_array($this->actual_json)
            && ($this->actual_json['_system_calculated'] ?? false) === true;
    }

    public function systemActualDecimal(): ?float
    {
        return $this->isSystemSourced() && $this->actual_decimal !== null
            ? (float) $this->actual_decimal
            : null;
    }
}
