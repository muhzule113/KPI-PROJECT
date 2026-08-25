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
use Exception;
use Illuminate\Support\Facades\DB;

class PeriodService
{
    public function validateReadiness(KpiPeriod $period): array
    {
        $issues = [];

        // Check deadlines logic
        if ($period->submission_deadline >= $period->review_deadline) {
            $issues[] = 'Deadline submission harus lebih awal dari deadline review.';
        }
        if ($period->review_deadline >= $period->approval_deadline) {
            $issues[] = 'Deadline review harus lebih awal dari deadline approval.';
        }

        // Get eligible employees
        $employees = Employee::where('status', 'active')->get();
        if ($employees->isEmpty()) {
            $issues[] = 'Tidak ada karyawan aktif yang terdaftar di sistem.';
        }

        // Check active template for each position (excluding executive owner roles)
        $positionIds = $employees->pluck('position_id')->unique();
        foreach ($positionIds as $posId) {
            $pos = \App\Models\Position::find($posId);
            if ($pos && in_array($pos->code, ['POS-OWN', 'POS-EXEC'])) {
                continue; // Executive / Owner does not undergo operational KPI generation
            }

            $template = KpiTemplate::where('position_id', $posId)->where('is_active', true)->first();
            if (!$template) {
                $issues[] = "Jabatan '{$pos?->name}' belum memiliki Template KPI aktif.";
                continue;
            }

            $activeVersion = KpiTemplateVersion::where('kpi_template_id', $template->id)
                ->where('status', 'active')
                ->first();

            if (!$activeVersion) {
                $issues[] = "Template KPI '{$template->name}' belum memiliki versi yang berstatus ACTIVE.";
            } elseif (abs((float)$activeVersion->total_weight - 100.00) > 0.001) {
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
        $employees = Employee::with(['position', 'supervisor'])->where('status', 'active')->get();
        $generatedCount = 0;

        DB::transaction(function () use ($period, $employees, &$generatedCount) {
            foreach ($employees as $employee) {
                // Find active template version for employee's position
                $template = KpiTemplate::where('position_id', $employee->position_id)
                    ->where('is_active', true)
                    ->first();

                if (!$template) continue;

                $version = KpiTemplateVersion::with(['items.definition', 'items.rubric.criteria'])
                    ->where('kpi_template_id', $template->id)
                    ->where('status', 'active')
                    ->first();

                if (!$version) continue;

                // Idempotent: check if employee KPI already exists for this period
                $employeeKpi = EmployeeKpi::firstOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'template_version_id' => $version->id,
                        'supervisor_id_snapshot' => $employee->supervisor_id,
                        'manager_id_snapshot' => null, // filled if hierarchy has manager
                        'status' => 'draft',
                        'progress_percentage' => 0.0,
                        'revision_number' => 0,
                        'row_version' => 1,
                    ]
                );

                if ($employeeKpi->wasRecentlyCreated) {
                    $generatedCount++;

                    // Generate items snapshot
                    foreach ($version->items as $tplItem) {
                        $rubricSnapshot = null;
                        if ($tplItem->rubric) {
                            $rubricSnapshot = [
                                'rubric_name' => $tplItem->rubric->name,
                                'criteria' => $tplItem->rubric->criteria->map(function ($c) {
                                    return [
                                        'id' => $c->id,
                                        'criterion_text' => $c->criterion_text,
                                        'points' => (float) $c->points,
                                        'is_mandatory' => $c->is_mandatory,
                                    ];
                                })->toArray(),
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
                    }
                }
            }

            $period->total_eligible_employees = $period->employeeKpis()->count();
            $period->save();

            AuditEvent::log(
                action: 'generate_kpi_snapshots',
                subjectType: 'KpiPeriod',
                subjectId: (string) $period->id,
                after: ['generated_count' => $generatedCount, 'total_eligible' => $period->total_eligible_employees]
            );
        });

        return $generatedCount;
    }

    public function openPeriod(KpiPeriod $period): void
    {
        $readiness = $this->validateReadiness($period);
        if (!$readiness['is_ready']) {
            throw new Exception('Periode belum siap dibuka: ' . implode(' ', $readiness['issues']));
        }

        // Generate snapshots if not already done
        if ($period->employeeKpis()->count() === 0) {
            $this->generateSnapshots($period);
        }

        $before = ['status' => $period->status];
        $period->status = 'OPEN';
        $period->opened_at = now();
        $period->save();

        AuditEvent::log(
            action: 'open_period',
            subjectType: 'KpiPeriod',
            subjectId: (string) $period->id,
            before: $before,
            after: ['status' => 'OPEN', 'opened_at' => $period->opened_at]
        );

        // Notify employees
        foreach ($period->employeeKpis as $kpi) {
            if ($kpi->employee?->user_id) {
                SystemNotification::send(
                    userId: $kpi->employee->user_id,
                    title: "Periode KPI Dibuka: {$period->name}",
                    body: "Periode {$period->name} telah dibuka. Silakan mulai melengkapi data target dan aktual KPI Anda sebelum deadline " . $period->submission_deadline->format('d M Y H:i'),
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
    }

    public function publishPeriod(KpiPeriod $period): void
    {
        $period->status = 'PUBLISHED';
        $period->published_at = now();
        $period->save();

        AuditEvent::log(
            action: 'publish_period',
            subjectType: 'KpiPeriod',
            subjectId: (string) $period->id,
            after: ['status' => 'PUBLISHED', 'published_at' => $period->published_at]
        );
    }

    public function lockPeriod(KpiPeriod $period): void
    {
        $period->status = 'LOCKED';
        $period->locked_at = now();
        $period->save();

        // Lock all child KPIs
        $period->employeeKpis()->where('status', 'approved')->update([
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        AuditEvent::log(
            action: 'lock_period',
            subjectType: 'KpiPeriod',
            subjectId: (string) $period->id,
            after: ['status' => 'LOCKED', 'locked_at' => $period->locked_at]
        );
    }
}
