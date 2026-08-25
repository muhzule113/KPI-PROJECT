<?php

namespace App\Modules\Approval;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiApproval;
use App\Models\KpiCorrectionRequest;
use App\Models\SystemNotification;
use App\Modules\Calculation\KpiCalculationEngine;
use Exception;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function getApprovalQueue()
    {
        return EmployeeKpi::with(['employee.position', 'employee.branch', 'period', 'items'])
            ->where('status', 'pending_approval')
            ->orderByDesc('verified_at')
            ->get();
    }

    public function approve(EmployeeKpi $kpi, ?string $note = null, ?int $approverId = null): array
    {
        $approverUser = $approverId ?? auth()->id();
        $approverEmployee = Employee::where('user_id', $approverUser)->first();

        // 1. Separation of duties: No Self-Approval
        if ($approverEmployee && $approverEmployee->id === $kpi->employee_id) {
            throw new Exception("Pemisahan tugas (No Self-Approval): Anda tidak dapat menyetujui penilaian KPI Anda sendiri.");
        }

        if ($kpi->status !== 'pending_approval') {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak berada dalam antrean approval.");
        }

        return DB::transaction(function () use ($kpi, $note, $approverUser) {
            $beforeStatus = $kpi->status;

            // Final calculation
            $calcResult = $this->calculationEngine->calculateKpi($kpi, 'approval', $approverUser);

            $kpi->status = 'approved';
            $kpi->approved_at = now();
            $kpi->locked_at = now();
            $kpi->row_version += 1;
            $kpi->save();

            // Lock all items
            foreach ($kpi->items as $item) {
                $item->status = 'locked';
                $item->row_version += 1;
                $item->save();
            }

            // Record approval
            KpiApproval::create([
                'employee_kpi_id' => $kpi->id,
                'approver_id' => $approverUser,
                'action' => 'approved',
                'reason' => $note,
                'row_version_snapshot' => $kpi->row_version,
            ]);

            AuditEvent::log(
                action: 'approve_kpi',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: [
                    'status' => 'approved',
                    'final_score' => $kpi->final_score,
                    'rating_code' => $kpi->rating_code,
                    'rating_label' => $kpi->rating_label,
                    'approved_at' => $kpi->approved_at,
                ],
                reason: $note,
                actorId: $approverUser
            );

            // Notify Employee
            if ($kpi->employee?->user_id) {
                SystemNotification::send(
                    userId: $kpi->employee->user_id,
                    title: "KPI Disetujui & Final: {$kpi->period->name}",
                    body: "Selamat! KPI Anda untuk periode {$kpi->period->name} telah disetujui oleh Manajemen dengan skor akhir {$kpi->final_score} ({$kpi->rating_label}).",
                    type: 'kpi_approved',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/my-kpi/{$kpi->id}"
                );
            }

            // Notify Supervisor
            if ($kpi->supervisorSnapshot?->user_id) {
                SystemNotification::send(
                    userId: $kpi->supervisorSnapshot->user_id,
                    title: "KPI Tim Selesai: {$kpi->employee->name}",
                    body: "KPI {$kpi->employee->name} telah disetujui Manajemen dengan skor {$kpi->final_score} ({$kpi->rating_label}).",
                    type: 'kpi_approved',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/supervisor/review/{$kpi->id}"
                );
            }

            return [
                'success' => true,
                'message' => 'KPI berhasil disetujui dan dikunci (final).',
                'kpi' => $kpi->fresh(),
                'calculation' => $calcResult,
            ];
        });
    }

    public function return(EmployeeKpi $kpi, string $reason, ?int $approverId = null): array
    {
        $approverUser = $approverId ?? auth()->id();
        $approverEmployee = Employee::where('user_id', $approverUser)->first();

        // 1. Separation of duties: No Self-Approval / Return
        if ($approverEmployee && $approverEmployee->id === $kpi->employee_id) {
            throw new Exception("Pemisahan tugas: Anda tidak dapat mengembalikan penilaian KPI Anda sendiri.");
        }

        if (empty(trim($reason))) {
            throw new Exception("Pengembalian KPI ke Supervisor wajib menyertakan alasan yang jelas.");
        }

        if ($kpi->status !== 'pending_approval') {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak berada dalam antrean approval.");
        }

        return DB::transaction(function () use ($kpi, $reason, $approverUser) {
            $beforeStatus = $kpi->status;

            $kpi->status = 'under_review';
            $kpi->row_version += 1;
            $kpi->save();

            KpiApproval::create([
                'employee_kpi_id' => $kpi->id,
                'approver_id' => $approverUser,
                'action' => 'returned',
                'reason' => $reason,
                'row_version_snapshot' => $kpi->row_version,
            ]);

            AuditEvent::log(
                action: 'return_kpi_to_supervisor',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: ['status' => 'under_review'],
                reason: $reason,
                actorId: $approverUser
            );

            // Notify Supervisor
            if ($kpi->supervisorSnapshot?->user_id) {
                SystemNotification::send(
                    userId: $kpi->supervisorSnapshot->user_id,
                    title: "KPI Dikembalikan oleh Manager: {$kpi->employee->name}",
                    body: "Manager mengembalikan review KPI {$kpi->employee->name}. Alasan: {$reason}",
                    type: 'kpi_returned',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/supervisor/review/{$kpi->id}"
                );
            }

            return [
                'success' => true,
                'message' => 'KPI berhasil dikembalikan ke Supervisor untuk ditinjau ulang.',
                'kpi' => $kpi->fresh(),
            ];
        });
    }

    public function requestCorrection(
        EmployeeKpi $kpi,
        string $reason,
        array $afterData,
        ?int $requesterId = null
    ): KpiCorrectionRequest {
        if (!in_array($kpi->status, ['approved', 'locked'])) {
            throw new Exception("Permintaan koreksi data resmi hanya berlaku untuk KPI yang sudah disetujui / locked.");
        }

        $beforeData = [
            'final_score' => $kpi->final_score,
            'rating_code' => $kpi->rating_code,
            'rating_label' => $kpi->rating_label,
            'items' => $kpi->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'actual' => $item->actual_decimal,
                    'weighted_score' => $item->weighted_score,
                ];
            })->toArray(),
        ];

        return KpiCorrectionRequest::create([
            'employee_kpi_id' => $kpi->id,
            'requested_by' => $requesterId ?? auth()->id(),
            'reason' => $reason,
            'before_json' => $beforeData,
            'after_json' => $afterData,
            'status' => 'pending',
        ]);
    }

    public function approveCorrection(
        KpiCorrectionRequest $request,
        ?int $approverId = null
    ): void {
        $approverUser = $approverId ?? auth()->id();

        // Dual Authorization: requester cannot approve their own correction request
        if ($request->requested_by === $approverUser) {
            throw new Exception("Prinsip Dual Authorization: Pihak yang mengajukan koreksi tidak dapat menyetujui koreksinya sendiri.");
        }

        if ($request->status !== 'pending') {
            throw new Exception("Permintaan koreksi sudah tidak berstatus pending.");
        }

        DB::transaction(function () use ($request, $approverUser) {
            $kpi = $request->employeeKpi;

            // Apply item modifications if any
            if (!empty($request->after_json['items'])) {
                foreach ($request->after_json['items'] as $itemUpdate) {
                    if (isset($itemUpdate['id'], $itemUpdate['actual'])) {
                        $item = $kpi->items->firstWhere('id', $itemUpdate['id']);
                        if ($item) {
                            $item->actual_decimal = (float) $itemUpdate['actual'];
                            $item->save();
                            $this->calculationEngine->calculateItem($item);
                        }
                    }
                }
            }

            // Recalculate
            $this->calculationEngine->calculateKpi($kpi, 'correction', $approverUser);

            $kpi->revision_number += 1;
            $kpi->row_version += 1;
            $kpi->save();

            $request->approved_by = $approverUser;
            $request->status = 'applied';
            $request->applied_at = now();
            $request->save();

            AuditEvent::log(
                action: 'apply_kpi_correction',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: $request->before_json,
                after: [
                    'final_score' => $kpi->final_score,
                    'rating_code' => $kpi->rating_code,
                    'revision_number' => $kpi->revision_number,
                ],
                reason: $request->reason,
                actorId: $approverUser
            );
        });
    }

    public function rejectCorrection(
        KpiCorrectionRequest $request,
        ?int $rejectorId = null,
        ?string $rejectionReason = null
    ): void {
        if ($request->status !== 'pending') {
            throw new Exception("Permintaan koreksi sudah tidak berstatus pending.");
        }

        $rejectorUser = $rejectorId ?? auth()->id();

        DB::transaction(function () use ($request, $rejectorUser, $rejectionReason) {
            $request->status = 'rejected';
            $request->approved_by = $rejectorUser;
            $request->save();

            AuditEvent::log(
                action: 'reject_kpi_correction',
                subjectType: 'KpiCorrectionRequest',
                subjectId: (string) $request->id,
                before: ['status' => 'pending'],
                after: ['status' => 'rejected'],
                reason: $rejectionReason ?? $request->reason,
                actorId: $rejectorUser
            );
        });
    }
}
