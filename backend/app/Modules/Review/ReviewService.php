<?php

namespace App\Modules\Review;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiAssessment;
use App\Models\KpiAssessmentAnswer;
use App\Models\KpiReview;
use App\Models\KpiReviewItem;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function getReviewQueue(int $supervisorUserId)
    {
        $supervisor = Employee::where('user_id', $supervisorUserId)->first();
        $user = User::find($supervisorUserId);
        if (! $supervisor || ! $user) {
            return collect();
        }

        return EmployeeKpi::with(['employee.position', 'period', 'items'])
            ->where('supervisor_id_snapshot', $supervisor->id)
            ->whereIn('status', ['submitted', 'under_review', 'revision_required', 'verified'])
            ->orderByRaw("FIELD(status, 'submitted', 'under_review', 'revision_required', 'verified')")
            ->orderByDesc('submitted_at')
            ->get()->filter(fn (EmployeeKpi $kpi): bool => KpiWorkflow::canReviewKpi($user, $kpi))->values();
    }

    public function verifyItem(
        EmployeeKpiItem $item,
        string $decision, // valid, revision_required
        ?string $note = null,
        ?string $reason = null,
        ?int $reviewerId = null
    ): EmployeeKpiItem {
        $kpi = $item->employeeKpi;
        $reviewer = User::with('employee')->find($reviewerId ?? auth()->id());

        if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $kpi)) {
            throw new Exception('Anda tidak berwenang mereview KPI ini.');
        }

        if (! in_array($decision, ['valid', 'revision_required'], true)) {
            throw new Exception('Keputusan review tidak valid.');
        }

        if (! in_array($kpi->status, ['submitted', 'under_review', 'revision_required'], true)) {
            throw new Exception("KPI berstatus '{$kpi->status}' tidak dapat direview.");
        }

        if ($decision === 'revision_required' && empty($reason)) {
            throw new Exception('Permintaan revisi wajib menyertakan alasan yang jelas.');
        }

        DB::transaction(function () use ($item, $kpi, $decision, $note, $reason, $reviewerId) {
            $reviewerUser = $reviewerId ?? auth()->id();
            $lockedKpi = EmployeeKpi::with('employee')
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reviewer = User::with('employee')->find($reviewerUser);

            if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $lockedKpi)) {
                throw new Exception('Anda tidak berwenang mereview KPI ini.');
            }
            if (! in_array($lockedKpi->status, ['submitted', 'under_review', 'revision_required'], true)) {
                throw new Exception("KPI berstatus '{$lockedKpi->status}' tidak dapat direview.");
            }

            $item = EmployeeKpiItem::whereKey($item->id)
                ->where('employee_kpi_id', $lockedKpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($item->status === 'locked') {
                throw new Exception('Item KPI final tidak dapat direview ulang.');
            }

            // Find or create review session
            $review = KpiReview::firstOrCreate(
                ['employee_kpi_id' => $lockedKpi->id, 'reviewer_id' => $reviewerUser],
                ['status' => 'in_progress']
            );

            // Record item review decision
            KpiReviewItem::updateOrCreate(
                ['kpi_review_id' => $review->id, 'employee_kpi_item_id' => $item->id],
                [
                    'decision' => $decision,
                    'supervisor_note' => $note,
                    'reason' => $reason,
                ]
            );

            $item->status = $decision === 'valid' ? 'verified' : 'revision_required';
            $item->manager_decision = null;
            $item->manager_decided_at = null;
            $item->manager_decided_by = null;
            $item->row_version += 1;
            $item->save();

            if ($lockedKpi->status === 'submitted') {
                KpiWorkflow::assertKpiTransition($lockedKpi, 'under_review');
                $lockedKpi->status = 'under_review';
                $lockedKpi->save();
            }

            $this->calculationEngine->calculateItem($item);
        });

        $item->refresh();

        return $item;
    }

    public function submitRubricAssessment(
        EmployeeKpiItem $item,
        array $answers, // array of ['criterion_id' => int, 'is_fulfilled' => bool, 'notes' => ?string]
        ?int $reviewerId = null,
        ?string $managerDecision = null,
        ?string $managerNote = null,
    ): KpiAssessment {
        $item->loadMissing('employeeKpi.employee');
        $kpi = $item->employeeKpi;
        $reviewerUser = $reviewerId ?? auth()->id();
        $reviewer = User::with('employee')->find($reviewerUser);
        $managerCanAssess = $reviewer && $kpi->isSupervisorKpi() && KpiWorkflow::canManageKpi($reviewer, $kpi);
        $supervisorCanAssess = $reviewer && KpiWorkflow::canReviewKpi($reviewer, $kpi);
        if (! $reviewer || (! $managerCanAssess && ! $supervisorCanAssess)) {
            throw new Exception('Anda tidak berwenang mereview KPI ini.');
        }
        if ($managerCanAssess && ! in_array($managerDecision, ['valid', 'needs_correction', 'data_exception'], true)) {
            throw new Exception('Keputusan Manager untuk rubric tidak valid.');
        }
        if ($managerCanAssess
            && in_array($managerDecision, ['needs_correction', 'data_exception'], true)
            && trim((string) $managerNote) === '') {
            throw new Exception('Catatan wajib diisi untuk rubric yang bermasalah.');
        }
        $criteria = collect($item->rubric_snapshot['criteria'] ?? [])->keyBy(fn (array $criterion) => (string) $criterion['id']);
        $answerIds = collect($answers)->map(fn ($answer) => (string) ($answer['criterion_id'] ?? ''));
        if ($answerIds->duplicates()->isNotEmpty()) {
            throw new Exception('Kriteria rubrik tidak boleh dikirim dua kali.');
        }
        if ($item->status === 'locked' || in_array($kpi->status, ['approved', 'locked'], true)) {
            throw new Exception('KPI final tidak dapat dinilai ulang.');
        }
        if ($criteria->isEmpty()) {
            throw new Exception('Rubrik item belum memiliki kriteria yang valid.');
        }

        $answers = collect($answers)->map(function (array $answer) use ($criteria): array {
            $criterion = $criteria->get((string) ($answer['criterion_id'] ?? ''));
            if (! $criterion) {
                throw new Exception('Kriteria rubrik tidak valid untuk item ini.');
            }

            return [
                'criterion_id' => $criterion['id'],
                'criterion_text' => $criterion['criterion_text'],
                'points' => (float) $criterion['points'],
                'is_fulfilled' => (bool) ($answer['is_fulfilled'] ?? false),
                'notes' => $answer['notes'] ?? null,
            ];
        })->values()->all();

        if ($criteria->keys()->diff(collect($answers)->pluck('criterion_id')->map(fn ($id) => (string) $id))->isNotEmpty()) {
            throw new Exception('Semua kriteria rubrik wajib dinilai.');
        }

        return DB::transaction(function () use ($item, $kpi, $answers, $reviewerUser, $managerCanAssess, $managerDecision, $managerNote) {
            $lockedKpi = EmployeeKpi::with('employee')
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reviewer = User::with('employee')->find($reviewerUser);
            $managerCanAssess = $reviewer && $lockedKpi->isSupervisorKpi() && KpiWorkflow::canManageKpi($reviewer, $lockedKpi);
            $supervisorCanAssess = $reviewer && KpiWorkflow::canReviewKpi($reviewer, $lockedKpi);
            if (! $reviewer || (! $managerCanAssess && ! $supervisorCanAssess)) {
                throw new Exception('Anda tidak berwenang mereview KPI ini.');
            }
            $allowedStatuses = $managerCanAssess
                ? ['submitted', 'under_review', 'revision_required', 'pending_approval']
                : ['submitted', 'under_review', 'revision_required'];
            if (! in_array($lockedKpi->status, $allowedStatuses, true)) {
                throw new Exception("KPI berstatus '{$lockedKpi->status}' tidak dapat direview.");
            }

            $item = EmployeeKpiItem::whereKey($item->id)
                ->where('employee_kpi_id', $lockedKpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($item->status === 'locked' || in_array($lockedKpi->status, ['approved', 'locked'], true)) {
                throw new Exception('KPI final tidak dapat dinilai ulang.');
            }

            $before = [
                'status' => $item->status,
                'actual' => $item->actual_decimal,
                'achievement' => $item->achievement_percentage,
                'weighted_score' => $item->weighted_score,
            ];

            $review = KpiReview::firstOrCreate(
                ['employee_kpi_id' => $lockedKpi->id, 'reviewer_id' => $reviewerUser],
                ['status' => 'in_progress']
            );
            $kpi = $lockedKpi;

            $totalPoints = 0.0;
            $earnedPoints = 0.0;

            foreach ($answers as $ans) {
                $points = (float) $ans['points'];
                $totalPoints += $points;
                if ($ans['is_fulfilled']) {
                    $earnedPoints += $points;
                }
            }

            $rawAch = $totalPoints > 0 ? ($earnedPoints / $totalPoints) * 100.0 : 0.0;
            $achievement = min($rawAch, 100.0);
            if ($managerCanAssess
                && $managerDecision === 'valid'
                && $achievement < 100
                && trim((string) $managerNote) === '') {
                throw new Exception('Hasil rubric di bawah target wajib disertai catatan Manager.');
            }

            $assessment = KpiAssessment::updateOrCreate(
                ['employee_kpi_item_id' => $item->id],
                [
                    'kpi_review_id' => $review->id,
                    'assessed_by' => $reviewerUser,
                    'score_points' => $earnedPoints,
                    'total_points' => $totalPoints,
                    'calculated_achievement' => $achievement,
                ]
            );

            // Clean old answers
            $assessment->answers()->delete();

            foreach ($answers as $ans) {
                $points = (float) ($ans['points'] ?? 1.0);
                $isFulfilled = ! empty($ans['is_fulfilled']);
                KpiAssessmentAnswer::create([
                    'kpi_assessment_id' => $assessment->id,
                    'criterion_id' => $ans['criterion_id'] ?? null,
                    'criterion_text' => $ans['criterion_text'] ?? 'Kriteria',
                    'is_fulfilled' => $isFulfilled,
                    'points_earned' => $isFulfilled ? $points : 0.0,
                    'notes' => $ans['notes'] ?? null,
                ]);
            }

            $item->actual_decimal = $achievement;
            $item->status = $managerCanAssess && $managerDecision !== 'valid' ? 'revision_required' : ($managerCanAssess ? 'assessed' : 'verified');
            if ($managerCanAssess) {
                $item->manager_decision = $managerDecision;
                $item->manager_note = $managerNote;
                $item->manager_decided_by = $reviewerUser;
                $item->manager_decided_at = now();
            }
            $item->row_version += 1;
            $item->save();

            $this->calculationEngine->calculateItem($item);

            if ($managerCanAssess) {
                AuditEvent::log(
                    action: 'manager_assess_kpi_item',
                    subjectType: 'EmployeeKpiItem',
                    subjectId: (string) $item->id,
                    before: $before,
                    after: [
                        'status' => $item->status,
                        'actual' => $item->actual_decimal,
                        'achievement' => $item->achievement_percentage,
                        'weighted_score' => $item->weighted_score,
                    ],
                    reason: 'Penilaian rubric oleh Manager.',
                    actorId: $reviewerUser
                );
            }

            return $assessment;
        });
    }

    public function requestRevision(
        EmployeeKpi $kpi,
        string $generalReason,
        ?int $reviewerId = null
    ): void {
        $reviewerUser = $reviewerId ?? auth()->id();
        $reviewer = User::with('employee')->find($reviewerUser);
        if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $kpi)) {
            throw new Exception('Anda tidak berwenang mereview KPI ini.');
        }
        if (trim($generalReason) === '') {
            throw new Exception('Alasan revisi wajib diisi.');
        }

        DB::transaction(function () use ($kpi, $generalReason, $reviewerUser) {
            $lockedKpi = EmployeeKpi::with(['items', 'employee.user', 'period'])
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reviewer = User::with('employee')->find($reviewerUser);
            if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $lockedKpi)) {
                throw new Exception('Anda tidak berwenang mereview KPI ini.');
            }
            if (! in_array($lockedKpi->status, ['under_review', 'revision_required'], true)) {
                throw new Exception("KPI berstatus '{$lockedKpi->status}' tidak dapat diminta revisi.");
            }

            $revisionItems = $lockedKpi->items->where('status', 'revision_required');
            if ($revisionItems->isEmpty()) {
                throw new Exception('Pilih setidaknya satu indikator yang perlu direvisi sebelum mengirim permintaan revisi.');
            }

            $beforeStatus = $lockedKpi->status;
            if ($beforeStatus !== 'revision_required') {
                KpiWorkflow::assertKpiTransition($lockedKpi, 'revision_required');
            }
            $lockedKpi->status = 'revision_required';
            $lockedKpi->revision_number += 1;
            $lockedKpi->row_version += 1;
            $lockedKpi->save();

            AuditEvent::log(
                action: 'request_kpi_revision',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $lockedKpi->id,
                before: ['status' => $beforeStatus],
                after: ['status' => 'revision_required', 'revision_number' => $lockedKpi->revision_number],
                reason: $generalReason,
                actorId: $reviewerUser
            );

            if ($lockedKpi->employee?->user_id) {
                $itemNames = $revisionItems->pluck('name_snapshot')->join(', ');
                SystemNotification::send(
                    userId: $lockedKpi->employee->user_id,
                    title: "Perlu Revisi KPI: {$lockedKpi->period->name}",
                    body: "Supervisor meminta revisi pada indikator: {$itemNames}. Catatan: {$generalReason}",
                    type: 'revision_required',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $lockedKpi->id,
                    actionUrl: "/my-kpi/{$lockedKpi->id}"
                );
            }
        });
    }

    public function forwardToManager(
        EmployeeKpi $kpi,
        ?string $supervisorNotes = null,
        ?int $reviewerId = null
    ): array {
        $reviewerUser = $reviewerId ?? auth()->id();
        $reviewer = User::with('employee')->find($reviewerUser);
        if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $kpi)) {
            throw new Exception('Anda tidak berwenang mereview KPI ini.');
        }

        return DB::transaction(function () use ($kpi, $supervisorNotes, $reviewerUser) {
            $lockedKpi = EmployeeKpi::with(['items', 'employee.position', 'period', 'managerSnapshot.user'])
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reviewer = User::with('employee')->find($reviewerUser);
            if (! $reviewer || ! KpiWorkflow::canReviewKpi($reviewer, $lockedKpi)) {
                throw new Exception('Anda tidak berwenang mereview KPI ini.');
            }
            if (! in_array($lockedKpi->status, ['submitted', 'under_review', 'revision_required', 'verified'], true)) {
                throw new Exception("KPI berstatus '{$lockedKpi->status}' tidak dapat diteruskan ke Manager.");
            }

            app(DailyAssessmentService::class)->aggregateKpi($lockedKpi, $reviewerUser);
            $lockedKpi->refresh()->load('items');
            $unverified = $lockedKpi->items->filter(fn ($item) => ! in_array($item->status, ['verified', 'assessed'], true));
            if ($unverified->isNotEmpty()) {
                $names = $unverified->pluck('name_snapshot')->join(', ');
                throw new Exception("Semua indikator harus diverifikasi sebelum diteruskan ke Manager. Indikator belum selesai: {$names}");
            }

            $beforeStatus = $lockedKpi->status;
            KpiWorkflow::assertKpiTransition($lockedKpi, 'pending_approval');
            $calcResult = $this->calculationEngine->calculateKpi($lockedKpi, 'review', $reviewerUser);
            if (! $calcResult['all_calculated']) {
                throw new Exception('KPI belum dapat diteruskan karena ada indikator yang tidak dapat dihitung atau konfigurasi targetnya belum valid.');
            }

            $lockedKpi->status = 'pending_approval';
            $lockedKpi->verified_at = now();
            $lockedKpi->row_version += 1;
            $lockedKpi->save();
            $kpi = $lockedKpi;

            AuditEvent::log(
                action: 'forward_kpi_to_manager',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: [
                    'status' => 'pending_approval',
                    'final_score' => $kpi->final_score,
                    'rating_code' => $kpi->rating_code,
                    'verified_at' => $kpi->verified_at,
                ],
                reason: $supervisorNotes,
                actorId: $reviewerUser
            );

            // Notify only the Manager captured by the KPI snapshot.
            $manager = $kpi->managerSnapshot?->user;
            if ($manager) {
                SystemNotification::send(
                    userId: $manager->id,
                    title: "KPI Menunggu Approval: {$kpi->employee->name}",
                    body: "Rekap KPI {$kpi->employee->name} sudah lengkap. Periksa hasil dan bukti, lalu setujui atau kembalikan indikator yang perlu dikoreksi.",
                    type: 'kpi_verified',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/manager/approval/{$kpi->id}"
                );
            }

            return [
                'success' => true,
                'message' => 'KPI berhasil diverifikasi dan diteruskan ke Manager untuk penilaian akhir dan approval.',
                'kpi' => $kpi->fresh(),
                'calculation' => $calcResult,
            ];
        });
    }
}
