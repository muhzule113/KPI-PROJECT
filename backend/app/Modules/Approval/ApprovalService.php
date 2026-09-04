<?php

namespace App\Modules\Approval;

use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiActualEntry;
use App\Models\KpiApproval;
use App\Models\KpiCorrectionRequest;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Calculation\KpiCalculationEngine;
use Exception;
use App\Support\KpiWorkflow;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function getApprovalQueue(?User $manager = null)
    {
        $query = EmployeeKpi::with(['employee.position', 'employee.branch', 'period', 'items'])
            ->where('status', 'pending_approval');

        if ($manager && !$manager->hasRole('super_admin')) {
            $employee = $manager->employee;
            if (!$employee) {
                return collect();
            }

            $query->where('manager_id_snapshot', $employee->id)
                ->whereHas('employee', fn ($q) => $q->where('branch_id', $employee->branch_id));
        }

        return $query->orderByDesc('verified_at')->get();
    }

    public function assessItem(
        EmployeeKpiItem $item,
        float $actualDecimal,
        ?string $note = null,
        ?int $assessorId = null
    ): EmployeeKpiItem {
        $assessorUser = $assessorId ?? auth()->id();

        if (!is_finite($actualDecimal)) {
            throw new Exception('Nilai penilaian harus berupa angka yang valid.');
        }

        return DB::transaction(function () use ($item, $actualDecimal, $note, $assessorUser) {
            $lockedItem = EmployeeKpiItem::whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();
            $kpi = EmployeeKpi::with('employee')
                ->whereKey($lockedItem->employee_kpi_id)
                ->lockForUpdate()
                ->firstOrFail();

            $assessor = User::with('employee')->find($assessorUser);
            if (!$assessor || !KpiWorkflow::canManageKpi($assessor, $kpi)) {
                throw new Exception('Anda tidak berwenang menilai KPI ini.');
            }
            if ($kpi->status !== 'pending_approval') {
                throw new Exception("KPI berstatus '{$kpi->status}' belum berada di tahap penilaian Manager.");
            }
            if ($lockedItem->status === 'locked') {
                throw new Exception('Indikator KPI final tidak dapat dinilai ulang.');
            }
            if (!in_array($lockedItem->status, ['verified', 'assessed'], true)) {
                throw new Exception('Indikator belum selesai diverifikasi Supervisor.');
            }
            if ($lockedItem->formula_key_snapshot === 'rubric') {
                throw new Exception('Indikator rubric harus dinilai melalui checklist.');
            }

            $before = [
                'status' => $lockedItem->status,
                'actual' => $lockedItem->actual_decimal,
                'achievement' => $lockedItem->achievement_percentage,
                'weighted_score' => $lockedItem->weighted_score,
            ];

            $lockedItem->actual_decimal = $actualDecimal;
            $lockedItem->status = 'assessed';
            $lockedItem->row_version += 1;
            $lockedItem->save();

            KpiActualEntry::create([
                'employee_kpi_item_id' => $lockedItem->id,
                'input_by' => $assessorUser,
                'actual_value' => $actualDecimal,
                'notes' => $note,
            ]);

            $calculation = $this->calculationEngine->calculateItem($lockedItem);
            if ($calculation->status !== 'calculated') {
                throw new Exception($calculation->note ?? 'Nilai Manager belum dapat dihitung.');
            }

            $kpi->calculateProgress();

            AuditEvent::log(
                action: 'manager_assess_kpi_item',
                subjectType: 'EmployeeKpiItem',
                subjectId: (string) $lockedItem->id,
                before: $before,
                after: [
                    'status' => $lockedItem->status,
                    'actual' => $lockedItem->actual_decimal,
                    'achievement' => $lockedItem->achievement_percentage,
                    'weighted_score' => $lockedItem->weighted_score,
                ],
                reason: $note,
                actorId: $assessorUser
            );

            return $lockedItem->fresh();
        });
    }

    public function approve(EmployeeKpi $kpi, ?string $note = null, ?int $approverId = null): array
    {
        $approverUser = $approverId ?? auth()->id();

        return DB::transaction(function () use ($kpi, $note, $approverUser) {
            $kpi = EmployeeKpi::with(['items', 'employee', 'period', 'supervisorSnapshot', 'managerSnapshot'])
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $approver = User::with('employee')->find($approverUser);
            $approverEmployee = $approver?->employee;

            if (!$approver || !$approverEmployee) {
                throw new Exception('Akun approver tidak memiliki profil karyawan yang valid.');
            }
            if ($approverEmployee->id === $kpi->employee_id) {
                throw new Exception("Pemisahan tugas (No Self-Approval): Anda tidak dapat menyetujui penilaian KPI Anda sendiri.");
            }
            if (!KpiWorkflow::canManageKpi($approver, $kpi)) {
                throw new Exception('Anda tidak berwenang menyetujui KPI ini.');
            }
            if ($kpi->status !== 'pending_approval') {
                throw new Exception("KPI berstatus '{$kpi->status}' dan tidak berada dalam antrean approval.");
            }

            $beforeStatus = $kpi->status;
            $calcResult = $this->calculationEngine->calculateKpi($kpi, 'approval', $approverUser);
            if (!$calcResult['all_calculated']) {
                throw new Exception('KPI belum dapat dikunci karena ada indikator yang belum terhitung atau UNSCORABLE.');
            }

            KpiWorkflow::assertKpiTransition($kpi, 'approved');
            foreach ($kpi->items as $item) {
                if (!in_array($item->status, ['verified', 'assessed', 'locked'], true)) {
                    throw new Exception("Indikator '{$item->name_snapshot}' belum selesai direview.");
                }
            }

            // Keep the existing API contract: approval is final, while item rows are locked.
            $kpi->status = 'approved';
            $kpi->approved_at = now();
            $kpi->locked_at = now();
            $kpi->row_version += 1;
            $kpi->save();

            foreach ($kpi->items as $item) {
                if ($item->status !== 'locked') {
                    $item->status = 'locked';
                    $item->row_version += 1;
                    $item->save();
                }
            }

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
                    'locked_at' => $kpi->locked_at,
                ],
                reason: $note,
                actorId: $approverUser
            );

            if ($kpi->employee?->user_id) {
                SystemNotification::send(
                    userId: $kpi->employee->user_id,
                    title: "KPI Disetujui & Final: {$kpi->period->name}",
                    body: "KPI Anda untuk periode {$kpi->period->name} telah disetujui dengan skor akhir {$kpi->final_score} ({$kpi->rating_label}).",
                    type: 'kpi_approved',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/my-kpi/{$kpi->id}"
                );
            }

            if ($kpi->supervisorSnapshot?->user_id) {
                SystemNotification::send(
                    userId: $kpi->supervisorSnapshot->user_id,
                    title: "KPI Tim Selesai: {$kpi->employee->name}",
                    body: "KPI {$kpi->employee->name} telah disetujui dengan skor {$kpi->final_score} ({$kpi->rating_label}).",
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
        if (empty(trim($reason))) {
            throw new Exception("Pengembalian KPI ke Supervisor wajib menyertakan alasan yang jelas.");
        }

        return DB::transaction(function () use ($kpi, $reason, $approverUser) {
            $kpi = EmployeeKpi::with(['employee', 'period', 'supervisorSnapshot'])
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $approver = User::with('employee')->find($approverUser);

            if (!$approver || !$approver->employee) {
                throw new Exception('Akun approver tidak memiliki profil karyawan yang valid.');
            }
            if ($approver->employee->id === $kpi->employee_id) {
                throw new Exception("Pemisahan tugas: Anda tidak dapat mengembalikan penilaian KPI Anda sendiri.");
            }
            if (!KpiWorkflow::canManageKpi($approver, $kpi)) {
                throw new Exception('Anda tidak berwenang mengembalikan KPI ini.');
            }
            if ($kpi->status !== 'pending_approval') {
                throw new Exception("KPI berstatus '{$kpi->status}' dan tidak berada dalam antrean approval.");
            }

            $beforeStatus = $kpi->status;
            KpiWorkflow::assertKpiTransition($kpi, 'under_review');
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
        $requesterUser = $requesterId ?? auth()->id();
        if (trim($reason) === '') {
            throw new Exception('Alasan koreksi wajib diisi.');
        }

        $requester = User::with('employee')->find($requesterUser);
        $kpi->loadMissing('employee');
        if (!$requester || !KpiWorkflow::canRequestCorrection($requester, $kpi)) {
            throw new Exception('Anda tidak berwenang mengajukan koreksi KPI ini.');
        }

        return DB::transaction(function () use ($kpi, $reason, $afterData, $requesterUser) {
            $kpi = EmployeeKpi::with(['items', 'employee'])
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (!in_array($kpi->status, ['approved', 'locked'], true)) {
                throw new Exception("Permintaan koreksi data resmi hanya berlaku untuk KPI yang sudah disetujui / locked.");
            }
            if (KpiCorrectionRequest::where('employee_kpi_id', $kpi->id)->where('status', 'pending')->exists()) {
                throw new Exception('KPI ini sudah memiliki permintaan koreksi yang sedang menunggu persetujuan.');
            }
            if (!is_array($afterData['items'] ?? null) || empty($afterData['items'])) {
                throw new Exception('Koreksi harus memuat minimal satu item KPI.');
            }

            $items = $kpi->items->keyBy(fn ($item) => (string) $item->id);
            $updates = [];
            foreach ($afterData['items'] as $itemUpdate) {
                $itemId = (string) ($itemUpdate['id'] ?? '');
                if (isset($updates[$itemId])) {
                    throw new Exception('Item KPI tidak boleh dikirim dua kali.');
                }
                $item = $items->get($itemId);
                if (!$item || !array_key_exists('actual', $itemUpdate) || !is_numeric($itemUpdate['actual'])) {
                    throw new Exception('Item atau nilai aktual koreksi tidak valid.');
                }
                $updates[$itemId] = [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'actual' => (float) $itemUpdate['actual'],
                ];
            }

            $beforeData = [
                'final_score' => $kpi->final_score,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'items' => $kpi->items->map(fn ($item) => [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'actual' => $item->actual_decimal,
                    'weighted_score' => $item->weighted_score,
                ])->toArray(),
            ];

            return KpiCorrectionRequest::create([
                'employee_kpi_id' => $kpi->id,
                'requested_by' => $requesterUser,
                'reason' => $reason,
                'before_json' => $beforeData,
                'after_json' => ['items' => array_values($updates)],
                'status' => 'pending',
                'row_version_snapshot' => $kpi->row_version,
            ]);
        });
    }

    public function approveCorrection(
        KpiCorrectionRequest $request,
        ?int $approverId = null
    ): void {
        $approverUser = $approverId ?? auth()->id();

        if ($request->requested_by === $approverUser) {
            throw new Exception("Prinsip Dual Authorization: Pihak yang mengajukan koreksi tidak dapat menyetujui koreksinya sendiri.");
        }

        DB::transaction(function () use ($request, $approverUser) {
            $request = KpiCorrectionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw new Exception("Permintaan koreksi sudah tidak berstatus pending.");
            }

            $kpi = EmployeeKpi::with('items')
                ->whereKey($request->employee_kpi_id)
                ->lockForUpdate()
                ->firstOrFail();
            $approver = User::with('employee')->find($approverUser);
            if (!$approver || !KpiWorkflow::canApproveCorrection($approver, $request)) {
                throw new Exception('Anda tidak berwenang menyetujui koreksi KPI ini.');
            }
            if ((int) $request->row_version_snapshot !== (int) $kpi->row_version) {
                throw new Exception('KPI telah berubah sejak koreksi diajukan. Buat permintaan koreksi baru.');
            }

            foreach ($request->after_json['items'] ?? [] as $itemUpdate) {
                $item = $kpi->items->firstWhere('id', $itemUpdate['id'] ?? null);
                if (!$item || !array_key_exists('actual', $itemUpdate) || !is_numeric($itemUpdate['actual'])) {
                    throw new Exception('Data item koreksi tidak valid.');
                }
                $item->actual_decimal = (float) $itemUpdate['actual'];
                $item->status = 'verified';
                $item->row_version += 1;
                $item->save();
                $this->calculationEngine->calculateItem($item);
            }

            $calcResult = $this->calculationEngine->calculateKpi($kpi, 'correction', $approverUser);
            if (!$calcResult['all_calculated']) {
                throw new Exception('Koreksi tidak dapat diterapkan karena hasil KPI belum dapat dihitung.');
            }

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
        $rejectorUser = $rejectorId ?? auth()->id();

        DB::transaction(function () use ($request, $rejectorUser, $rejectionReason) {
            $request = KpiCorrectionRequest::with('employeeKpi.employee')
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($request->status !== 'pending') {
                throw new Exception("Permintaan koreksi sudah tidak berstatus pending.");
            }
            $rejector = User::with('employee')->find($rejectorUser);
            if (!$rejector || !KpiWorkflow::canApproveCorrection($rejector, $request)) {
                throw new Exception('Anda tidak berwenang menolak koreksi KPI ini.');
            }
            if ($request->requested_by === $rejectorUser) {
                throw new Exception("Prinsip Dual Authorization: Pihak yang mengajukan koreksi tidak dapat menolak koreksinya sendiri.");
            }

            $request->approved_by = $rejectorUser;
            $request->rejection_reason = $rejectionReason ?: $request->reason;
            $request->status = 'rejected';
            $request->save();

            AuditEvent::log(
                action: 'reject_kpi_correction',
                subjectType: 'KpiCorrectionRequest',
                subjectId: (string) $request->id,
                before: ['status' => 'pending'],
                after: ['status' => 'rejected', 'rejection_reason' => $request->rejection_reason],
                reason: $request->rejection_reason,
                actorId: $rejectorUser
            );
        });
    }
}
