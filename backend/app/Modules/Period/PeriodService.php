<?php

namespace App\Modules\Period;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\EmployeePlacement;
use App\Models\KpiPeriod;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateVersion;
use App\Models\SystemNotification;
use App\Modules\Reporting\ReportSubmissionService;
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

        return EmployeePlacement::with(['employee.user.roles', 'position', 'branch', 'supervisor.user.roles'])
            ->whereIn('branch_id', $branchIds)
            ->whereDate('effective_from', '<=', $period->end_date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $period->start_date))
            ->whereHas('employee', fn ($query) => $query->where('status', 'active')
                ->whereDate('joined_at', '<=', $period->end_date)
                ->where(fn ($ended) => $ended->whereNull('ended_at')->orWhereDate('ended_at', '>=', $period->start_date)))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id')
            ->map(function (Collection $placements) use ($period): Employee {
                $placement = $placements->first(fn (EmployeePlacement $row): bool => $row->effective_from->lte($period->start_date)
                    && ($row->effective_until === null || $row->effective_until->gte($period->start_date)))
                    ?? $placements->first();
                $employee = $placement->employee;
                $employee->setRelation('snapshotPlacement', $placement);

                return $employee;
            })->values();
    }

    protected function managerFor(Employee $employee, KpiPeriod $period): ?Employee
    {
        /** @var EmployeePlacement|null $placement */
        $placement = $employee->getRelationValue('snapshotPlacement');
        $manager = $placement?->supervisor;
        $visited = [];

        while ($manager && ! in_array((string) $manager->id, $visited, true)) {
            $visited[] = (string) $manager->id;
            $managerPlacement = EmployeePlacement::with(['position', 'supervisor.user.roles'])
                ->where('employee_id', $manager->id)->effectiveOn($period->start_date)->first();
            if ($manager->status === 'active' && $manager->user?->is_active && $manager->user?->hasRole('owner_manager')
                && (string) $managerPlacement?->branch_id === (string) $placement?->branch_id
                && in_array($managerPlacement?->position?->code, ['POS-OWN', 'POS-EXEC'], true)) {
                return $manager;
            }
            $manager = $managerPlacement?->supervisor;
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

        foreach ($employees as $employee) {
            /** @var EmployeePlacement $placement */
            $placement = $employee->getRelationValue('snapshotPlacement');
            $supervisor = $placement->supervisor;
            $supervisorPlacement = $supervisor
                ? EmployeePlacement::where('employee_id', $supervisor->id)->effectiveOn($period->start_date)->first()
                : null;
            if ((! $supervisor || $supervisor->status !== 'active'
                    || ! $supervisor->user?->is_active
                    || ! $supervisor->user?->hasRole($placement->position?->code === 'POS-SPV' ? 'owner_manager' : 'supervisor')
                    || (string) $supervisorPlacement?->branch_id !== (string) $placement->branch_id)
                && ! in_array($placement->position?->code, ['POS-OWN', 'POS-EXEC'], true)) {
                $issues[] = "Karyawan '{$employee->name}' belum memiliki Supervisor aktif untuk snapshot.";
            }
            if (! $this->managerFor($employee, $period) && ! in_array($placement->position?->code, ['POS-OWN', 'POS-EXEC'], true)) {
                $issues[] = "Karyawan '{$employee->name}' belum memiliki Manager aktif untuk snapshot.";
            }
        }

        foreach ($employees->map(fn (Employee $employee) => $employee->getRelationValue('snapshotPlacement')?->position)->filter()->unique('id') as $position) {
            if (in_array($position->code, ['POS-OWN', 'POS-EXEC'], true)) {
                continue;
            }

            $template = KpiTemplate::where('position_id', $position->id)
                ->where('is_active', true)
                ->first();
            if (! $template) {
                $issues[] = "Jabatan '{$position->name}' belum memiliki Template KPI aktif.";

                continue;
            }

            $activeVersion = KpiTemplateVersion::with(['items.rubric.criteria'])
                ->where('kpi_template_id', $template->id)
                ->where('status', 'active')
                ->first();
            if (! $activeVersion) {
                $issues[] = "Template KPI '{$template->name}' belum memiliki versi aktif.";
            } elseif ($activeVersion->items->isEmpty() || abs((float) $activeVersion->items->sum('weight') - 100.00) > 0.001) {
                $issues[] = "Versi aktif Template '{$template->name}' harus memiliki indikator dengan total bobot tepat 100.00%.";
            } else {
                foreach ($activeVersion->items as $item) {
                    if ((float) $item->weight <= 0 || ! $item->definition?->is_active) {
                        $issues[] = "Indikator pada template '{$template->name}' harus aktif dengan bobot lebih besar dari nol.";
                    }
                    $formula = strtolower((string) $item->formula_key);
                    if (! in_array($formula, ['higher_is_better', 'lower_is_better', 'zero_tolerance', 'rubric'], true)) {
                        $issues[] = "Formula pada template '{$template->name}' tidak didukung.";
                    }
                    if (! in_array($item->formula_params['cadence'] ?? 'daily', ['daily', 'weekly', 'period'], true)) {
                        $issues[] = "Cadence pada template '{$template->name}' harus daily, weekly, atau period.";
                    }
                    $target = $item->target_value;
                    if (in_array($formula, ['higher_is_better', 'lower_is_better'], true)
                        && ($target === null || (float) $target <= 0)) {
                        $issues[] = "Indikator '{$item->definition?->name}' pada template '{$template->name}' memiliki target 0/kosong.";
                    }
                    $targetData = $item->target_json ?? [];
                    $params = $item->formula_params ?? [];
                    if (in_array($formula, ['lower_is_better', 'zero_tolerance'], true)) {
                        $fullLimit = $targetData['full_score_limit'] ?? $params['full_score_limit'] ?? null;
                        $failureLimit = $targetData['failure_limit'] ?? $params['failure_limit'] ?? null;
                        $baseTarget = $formula === 'zero_tolerance' ? $fullLimit : $target;
                        if ($failureLimit === null || $baseTarget === null || (float) $failureLimit <= (float) $baseTarget) {
                            $issues[] = "Indikator '{$item->definition?->name}' pada template '{$template->name}' belum memiliki failure limit valid.";
                        }
                    }
                    if ($formula === 'rubric' && (! $item->rubric || $item->rubric->criteria->isEmpty())) {
                        $issues[] = "Indikator rubrik '{$item->definition?->name}' pada template '{$template->name}' belum memiliki kriteria.";
                    }
                }
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
                /** @var EmployeePlacement $placement */
                $placement = $employee->getRelationValue('snapshotPlacement');
                $template = KpiTemplate::where('position_id', $placement->position_id)
                    ->where('is_active', true)
                    ->first();
                $version = $template
                    ? KpiTemplateVersion::with(['items.definition', 'items.rubric.criteria', 'ratingScheme.bands'])
                        ->where('kpi_template_id', $template->id)
                        ->where('status', 'active')
                        ->first()
                    : null;

                if (! $version) {
                    continue;
                }

                $manager = $this->managerFor($employee, $period);
                $ratingBands = $version->ratingScheme?->bands->map(fn ($band): array => [
                    'code' => $band->code,
                    'label' => $band->label,
                    'min_score' => (string) $band->min_score,
                    'max_score' => (string) $band->max_score,
                    'sort_order' => $band->sort_order,
                ])->values()->all() ?? [];
                $employeeKpi = EmployeeKpi::firstOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'template_version_id' => $version->id,
                        'employee_number_snapshot' => $employee->employee_number,
                        'employee_name_snapshot' => $employee->name,
                        'supervisor_id_snapshot' => $placement->supervisor_id,
                        'manager_id_snapshot' => $manager?->id,
                        'placement_id_snapshot' => $placement->id,
                        'branch_id_snapshot' => $placement->branch_id,
                        'position_id_snapshot' => $placement->position_id,
                        'position_code_snapshot' => $placement->position?->code,
                        'eligibility' => $employee->joined_at->gt($period->start_date) || $placement->effective_from->gt($period->start_date) ? 'partial' : 'full',
                        'score_cap_snapshot' => $version->ratingScheme?->score_cap ?? 100,
                        'rating_bands_snapshot' => $ratingBands,
                        'status' => 'draft',
                        'progress_percentage' => 0.0,
                        'revision_number' => 0,
                        'row_version' => 1,
                    ]
                );

                if ($employeeKpi->wasRecentlyCreated) {
                    $generatedCount++;
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

                    if (strtolower((string) $tplItem->source_type) === 'supervisor') {
                        $rubricSnapshot ??= [];
                        $rubricSnapshot['manual_rating_options'] = $version->ratingScheme?->manualOptions() ?? [
                            ['code' => 'FAIR', 'label' => 'Cukup', 'score' => 75.00],
                            ['code' => 'GOOD', 'label' => 'Baik', 'score' => 85.00],
                            ['code' => 'VERY_GOOD', 'label' => 'Sangat Baik', 'score' => 95.00],
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

    public function markReady(KpiPeriod $period): void
    {
        if ($period->status === 'READY') {
            return;
        }
        KpiWorkflow::assertPeriodTransition($period, 'READY');
        $readiness = $this->validateReadiness($period);
        if (! $readiness['is_ready']) {
            throw new Exception('Periode belum siap: '.implode(' ', $readiness['issues']));
        }
        DB::transaction(function () use ($period): void {
            $locked = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($locked, 'READY');
            $this->generateSnapshots($locked);
            app(ReportSubmissionService::class)->schedule($locked);
            $locked->update(['status' => 'READY']);
            AuditEvent::log('period_ready', 'KpiPeriod', (string) $locked->id, before: ['status' => 'DRAFT'], after: ['status' => 'READY']);
        });
    }

    public function openPeriod(KpiPeriod $period): void
    {
        if ($period->status === 'OPEN') {
            return;
        }
        KpiWorkflow::assertPeriodTransition($period, 'OPEN');
        if ($period->total_eligible_employees < 1 || $period->employeeKpis()->doesntExist()) {
            throw new Exception('Periode tidak memiliki roster snapshot. Jalankan READY terlebih dahulu.');
        }
        if ($period->submission_deadline->isPast() || $period->review_deadline->isPast() || $period->approval_deadline->isPast()) {
            throw new Exception('Periode dengan deadline lampau tidak dapat dibuka.');
        }
        $branchIds = $period->branches()->pluck('branches.id');
        $overlap = KpiPeriod::query()->where('id', '!=', $period->id)->where('status', 'OPEN')
            ->whereDate('start_date', '<=', $period->end_date)->whereDate('end_date', '>=', $period->start_date)
            ->whereHas('branches', fn ($query) => $query->whereIn('branches.id', $branchIds))->exists();
        if ($overlap) {
            throw new Exception('Rentang periode bertumpang tindih dengan periode OPEN pada cabang yang sama.');
        }

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
                    body: "Periode {$period->name} telah dibuka. Catat pekerjaan melalui modul operasional; penilaian harian dilakukan oleh penilai yang ditugaskan.",
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

    public function startReview(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $lockedPeriod = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($lockedPeriod, 'IN_REVIEW');
            $kpis = $lockedPeriod->employeeKpis()->lockForUpdate()->get();
            if ($kpis->isEmpty() || $kpis->contains(fn (EmployeeKpi $kpi): bool => in_array($kpi->status, ['draft', 'revision_required'], true))) {
                throw new Exception('Periode belum dapat direview karena masih ada KPI yang belum disubmit.');
            }

            foreach ($kpis as $kpi) {
                if ($kpi->status === 'submitted') {
                    KpiWorkflow::assertKpiTransition($kpi, 'under_review');
                    $kpi->status = 'under_review';
                    $kpi->row_version += 1;
                    $kpi->save();
                }
            }

            $before = ['status' => $lockedPeriod->status];
            $lockedPeriod->status = 'IN_REVIEW';
            $lockedPeriod->save();
            AuditEvent::log(
                action: 'start_period_review',
                subjectType: 'KpiPeriod',
                subjectId: (string) $lockedPeriod->id,
                before: $before,
                after: ['status' => 'IN_REVIEW']
            );
        });
    }

    public function startApproval(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $lockedPeriod = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($lockedPeriod, 'WAITING_APPROVAL');
            $kpis = $lockedPeriod->employeeKpis()->lockForUpdate()->get();
            if ($kpis->isEmpty() || $kpis->contains(fn (EmployeeKpi $kpi): bool => ! $kpi->isSupervisorKpi()
                && ! in_array($kpi->status, ['verified', 'pending_approval', 'approved', 'locked'], true))) {
                throw new Exception('Periode belum dapat menunggu approval karena masih ada KPI yang belum diverifikasi.');
            }

            foreach ($kpis as $kpi) {
                if ($kpi->status === 'verified') {
                    KpiWorkflow::assertKpiTransition($kpi, 'pending_approval');
                    $kpi->status = 'pending_approval';
                    $kpi->row_version += 1;
                    $kpi->save();
                }
            }

            $before = ['status' => $lockedPeriod->status];
            $lockedPeriod->status = 'WAITING_APPROVAL';
            $lockedPeriod->save();
            AuditEvent::log(
                action: 'start_period_approval',
                subjectType: 'KpiPeriod',
                subjectId: (string) $lockedPeriod->id,
                before: $before,
                after: ['status' => 'WAITING_APPROVAL']
            );
        });
    }

    public function publishPeriod(KpiPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = KpiPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            KpiWorkflow::assertPeriodTransition($period, 'PUBLISHED');
            $kpis = $period->employeeKpis()->with('items')->lockForUpdate()->get();
            if ($kpis->isEmpty() || $kpis->contains(fn ($kpi) => ! in_array($kpi->status, ['approved', 'locked'], true))) {
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
            if ($kpis->isEmpty() || $kpis->contains(fn ($kpi) => ! in_array($kpi->status, ['approved', 'locked'], true))) {
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
