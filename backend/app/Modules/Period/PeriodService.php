<?php

namespace App\Modules\Period;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiPeriod;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateVersion;
use App\Models\SystemNotification;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PeriodService
{
    protected function eligibleEmployees(KpiPeriod $period): Collection
    {
        $branchIds = $period->branches()->pluck('branches.id');

        if ($branchIds->isEmpty()) {
            return collect();
        }

        return Employee::with(['position', 'supervisor'])
            ->where('status', 'active')
            ->whereIn('branch_id', $branchIds)
            ->get();
    }

    protected function managerFor(Employee $employee): ?Employee
    {
        $manager = $employee->supervisor;
        $visited = [];

        while ($manager && !in_array((string) $manager->id, $visited, true)) {
            $visited[] = (string) $manager->id;
            if (in_array($manager->position?->code, ['POS-OWN', 'POS-EXEC'], true)) {
                return $manager;
            }
            $manager = $manager->supervisor;
        }

        return null;
    }

    public function validateReadiness(KpiPeriod $period): array
    {
        $issues = [];

        if ($period->branches()->count() === 0) {
            $issues[] = 'Pilih minimal satu cabang peserta periode KPI.';
        }
        if ($period->submission_deadline >= $period->review_deadline) {
            $issues[] = 'Deadline submission harus lebih awal dari deadline review.';
        }
        if ($period->review_deadline >= $period->approval_deadline) {
            $issues[] = 'Deadline review harus lebih awal dari deadline approval.';
        }

        $employees = $this->eligibleEmployees($period);
        if ($employees->isEmpty()) {
            $issues[] = 'Tidak ada karyawan aktif pada cabang peserta periode.';
        }

        foreach ($employees->pluck('position')->filter()->unique('id') as $position) {
            if (in_array($position->code, ['POS-OWN', 'POS-EXEC'], true)) {
                continue;
            }

            $template = KpiTemplate::where('position_id', $position->id)
                ->where('is_active', true)
                ->first();
            if (!$template) {
                $issues[] = "Jabatan '{$position->name}' belum memiliki Template KPI aktif.";
                continue;
            }

            $activeVersion = KpiTemplateVersion::where('kpi_template_id', $template->id)
                ->where('status', 'active')
                ->first();
            if (!$activeVersion) {
                $issues[] = "Template KPI '{$template->name}' belum memiliki versi aktif.";
            } elseif (abs((float) $activeVersion->total_weight - 100.00) > 0.001) {
                $issues[] = "Versi aktif Template '{$template->name}' memiliki total bobot {$activeVersion->total_weight}%, harus tepat 100.00%.";
            }
        }

        return [
            'is_ready' => empty($issues),
            'issues' => $issues,
            'eligible_count' => $employees->count(),
        ];
    }

    public function generateSnapshots(KpiPeriod $period): int
    {
        $employees = $this->eligibleEmployees($period);
        $generatedCount = 0;
        $repairedItems = 0;

        DB::transaction(function () use ($period, $employees, &$generatedCount, &$repairedItems) {
            foreach ($employees as $employee) {
                $template = KpiTemplate::where('position_id', $employee->position_id)
                    ->where('is_active', true)
                    ->first();
                $version = $template
                    ? KpiTemplateVersion::with(['items.definition', 'items.rubric.criteria'])
                        ->where('kpi_template_id', $template->id)
                        ->where('status', 'active')
                        ->first()
                    : null;

                if (!$version) {
                    continue;
                }

                $manager = $this->managerFor($employee);
                $employeeKpi = EmployeeKpi::firstOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'template_version_id' => $version->id,
                        'supervisor_id_snapshot' => $employee->supervisor_id,
                        'manager_id_snapshot' => $manager?->id,
                        'status' => 'draft',
                        'progress_percentage' => 0.0,
                        'revision_number' => 0,
                        'row_version' => 1,
                    ]
                );

                if ($employeeKpi->wasRecentlyCreated) {
                    $generatedCount++;
                } elseif ($employeeKpi->manager_id_snapshot === null && $manager) {
                    $employeeKpi->manager_id_snapshot = $manager->id;
                    $employeeKpi->row_version += 1;
                    $employeeKpi->save();
                }

                foreach ($version->items as $tplItem) {
                    if ($employeeKpi->items()
                        ->where('kpi_definition_id', $tplItem->kpi_definition_id)
                        ->exists()) {
                        continue;
                    }

                    $rubricSnapshot = null;
                    if ($tplItem->rubric) {
                        $rubricSnapshot = [
                            'rubric_name' => $tplItem->rubric->name,
                            'criteria' => $tplItem->rubric->criteria->map(fn ($criterion) => [
                                'id' => $criterion->id,
                                'criterion_text' => $criterion->criterion_text,
                                'points' => (float) $criterion->points,
                                'is_mandatory' => $criterion->is_mandatory,
                            ])->toArray(),
                        ];
                    }

                    EmployeeKpiItem::create([
                        'employee_kpi_id' => $employeeKpi->id,
                        'kpi_definition_id' => $tplItem->kpi_definition_id,
                        'definition_code_snapshot' => $tplItem->definition?->code ?? 'KPI',
                        'name_snapshot' => $tplItem->definition?->name ?? 'Indikator',
                        'weight_snapshot' => $tplItem->weight,
                        'target_value_snapshot' => $tplItem->target_value,
                        'target_unit_snapshot' => $tplItem->target_unit,
                        'target_json_snapshot' => $tplItem->target_json,
                        'formula_key_snapshot' => $tplItem->formula_key,
                        'formula_params_snapshot' => $tplItem->formula_params,
                        'source_type_snapshot' => $tplItem->source_type,
                        'evidence_req_snapshot' => $tplItem->evidence_required,
                        'rubric_snapshot' => $rubricSnapshot,
                        'status' => 'not_started',
                        'calculation_status' => 'pending',
                        'row_version' => 1,
                    ]);
                    $repairedItems++;
                }
            }

            $period->total_eligible_employees = $employees->isEmpty()
                ? 0
                : $period->employeeKpis()->whereIn('employee_id', $employees->pluck('id'))->count();
            $period->save();

            AuditEvent::log(
                action: 'generate_kpi_snapshots',
                subjectType: 'KpiPeriod',
                subjectId: (string) $period->id,
                after: [
                    'generated_count' => $generatedCount,
                    'repaired_items' => $repairedItems,
                    'total_eligible' => $period->total_eligible_employees,
                ]
            );
        });

        return $generatedCount;
    }

    public function openPeriod(KpiPeriod $period): void
    {
        if ($period->status === 'OPEN') {
            $this->generateSnapshots($period);
            return;
        }

        $readiness = $this->validateReadiness($period);
        if (!$readiness['is_ready']) {
            throw new Exception('Periode belum siap dibuka: ' . implode(' ', $readiness['issues']));
        }
        KpiWorkflow::assertPeriodTransition($period, 'OPEN');
        $this->generateSnapshots($period);

        DB::transaction(function () use ($period) {
            $lockedPeriod = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($lockedPeriod->status === 'OPEN') {
                return;
            }
            KpiWorkflow::assertPeriodTransition($lockedPeriod, 'OPEN');
            $before = ['status' => $lockedPeriod->status];
            $lockedPeriod->status = 'OPEN';
            $lockedPeriod->opened_at = now();
            $lockedPeriod->save();

            AuditEvent::log(
                action: 'open_period',
                subjectType: 'KpiPeriod',
                subjectId: (string) $lockedPeriod->id,
                before: $before,
                after: ['status' => 'OPEN', 'opened_at' => $lockedPeriod->opened_at]
            );
        });

        $period->refresh()->load('employeeKpis.employee');
        foreach ($period->employeeKpis as $kpi) {
            if ($kpi->employee?->user_id) {
                SystemNotification::send(
                    userId: $kpi->employee->user_id,
                    title: "Periode KPI Dibuka: {$period->name}",
                    body: "Periode {$period->name} telah dibuka. Lengkapi data sebelum deadline {$period->submission_deadline->format('d M Y H:i')}.",
                    type: 'period_opened',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/my-kpi/{$kpi->id}"
                );
            }
        }
    }

    public function closeSubmission(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($period, 'SUBMISSION_CLOSED');
            $before = ['status' => $period->status];
            $period->status = 'SUBMISSION_CLOSED';
            $period->closed_at = now();
            $period->save();

            AuditEvent::log(
                action: 'close_submission',
                subjectType: 'KpiPeriod',
                subjectId: (string) $period->id,
                before: $before,
                after: ['status' => 'SUBMISSION_CLOSED']
            );
        });
    }

    public function publishPeriod(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($period, 'PUBLISHED');
            $kpis = $period->employeeKpis()->with('items')->lockForUpdate()->get();
            if ($kpis->isEmpty() || $kpis->contains(fn ($kpi) => !in_array($kpi->status, ['approved', 'locked'], true))) {
                throw new Exception('Periode belum dapat dipublish karena masih ada KPI yang belum disetujui.');
            }

            $before = ['status' => $period->status];
            $period->status = 'PUBLISHED';
            $period->published_at = now();
            $period->save();

            AuditEvent::log(
                action: 'publish_period',
                subjectType: 'KpiPeriod',
                subjectId: (string) $period->id,
                before: $before,
                after: ['status' => 'PUBLISHED', 'published_at' => $period->published_at]
            );
        });
    }

    public function lockPeriod(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($period, 'LOCKED');
            $kpis = $period->employeeKpis()->with('items')->lockForUpdate()->get();
            if ($kpis->isEmpty() || $kpis->contains(fn ($kpi) => !in_array($kpi->status, ['approved', 'locked'], true))) {
                throw new Exception('Periode belum dapat dikunci karena masih ada KPI yang belum final.');
            }

            foreach ($kpis as $kpi) {
                if ($kpi->status === 'approved') {
                    KpiWorkflow::assertKpiTransition($kpi, 'locked');
                    $kpi->status = 'locked';
                    $kpi->row_version += 1;
                    $kpi->locked_at = now();
                    $kpi->save();
                }
                $kpi->items()->where('status', '!=', 'locked')->update([
                    'status' => 'locked',
                    'row_version' => DB::raw('row_version + 1'),
                ]);
            }

            $before = ['status' => $period->status];
            $period->status = 'LOCKED';
            $period->locked_at = now();
            $period->save();

            AuditEvent::log(
                action: 'lock_period',
                subjectType: 'KpiPeriod',
                subjectId: (string) $period->id,
                before: $before,
                after: ['status' => 'LOCKED', 'locked_at' => $period->locked_at]
            );
        });
    }
}
