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
use App\Modules\Calculation\KpiCalculationEngine;
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
        if (!$supervisor) {
            return collect();
        }

        return EmployeeKpi::with(['employee.position', 'period', 'items'])
            ->where('supervisor_id_snapshot', $supervisor->id)
            ->whereIn('status', ['submitted', 'under_review', 'revision_required', 'verified'])
            ->orderByRaw("FIELD(status, 'submitted', 'under_review', 'revision_required', 'verified')")
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function verifyItem(
        EmployeeKpiItem $item,
        string $decision, // valid, revision_required
        ?string $note = null,
        ?string $reason = null,
        ?int $reviewerId = null
    ): EmployeeKpiItem {
        $kpi = $item->employeeKpi;

        if ($decision === 'revision_required' && empty($reason)) {
            throw new Exception("Permintaan revisi wajib menyertakan alasan yang jelas.");
        }

        DB::transaction(function () use ($item, $kpi, $decision, $note, $reason, $reviewerId) {
            $reviewerUser = $reviewerId ?? auth()->id();

            // Find or create review session
            $review = KpiReview::firstOrCreate(
                ['employee_kpi_id' => $kpi->id, 'reviewer_id' => $reviewerUser],
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
            $item->row_version += 1;
            $item->save();

            if ($kpi->status === 'submitted') {
                $kpi->status = 'under_review';
                $kpi->save();
            }

            $this->calculationEngine->calculateItem($item);
        });

        return $item->fresh();
    }

    public function submitRubricAssessment(
        EmployeeKpiItem $item,
        array $answers, // array of ['criterion_id' => int, 'is_fulfilled' => bool, 'criterion_text' => string, 'points' => float, 'notes' => ?string]
        ?int $reviewerId = null
    ): KpiAssessment {
        $kpi = $item->employeeKpi;
        $reviewerUser = $reviewerId ?? auth()->id();

        return DB::transaction(function () use ($item, $kpi, $answers, $reviewerUser) {
            $review = KpiReview::firstOrCreate(
                ['employee_kpi_id' => $kpi->id, 'reviewer_id' => $reviewerUser],
                ['status' => 'in_progress']
            );

            $totalPoints = 0.0;
            $earnedPoints = 0.0;

            foreach ($answers as $ans) {
                $points = (float) ($ans['points'] ?? 1.0);
                $totalPoints += $points;
                if (!empty($ans['is_fulfilled'])) {
                    $earnedPoints += $points;
                }
            }

            $rawAch = $totalPoints > 0 ? ($earnedPoints / $totalPoints) * 100.0 : 0.0;
            $achievement = min($rawAch, 100.0);

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
                $isFulfilled = !empty($ans['is_fulfilled']);
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
            $item->status = 'verified';
            $item->row_version += 1;
            $item->save();

            $this->calculationEngine->calculateItem($item);

            return $assessment;
        });
    }

    public function requestRevision(
        EmployeeKpi $kpi,
        string $generalReason,
        ?int $reviewerId = null
    ): void {
        $kpi->loadMissing(['items', 'employee.user']);

        $revisionItems = $kpi->items->where('status', 'revision_required');
        if ($revisionItems->isEmpty()) {
            throw new Exception("Pilih setidaknya satu indikator yang perlu direvisi sebelum mengirim permintaan revisi.");
        }

        DB::transaction(function () use ($kpi, $generalReason, $revisionItems, $reviewerId) {
            $beforeStatus = $kpi->status;
            $kpi->status = 'revision_required';
            $kpi->revision_number += 1;
            $kpi->row_version += 1;
            $kpi->save();

            AuditEvent::log(
                action: 'request_kpi_revision',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: ['status' => 'revision_required', 'revision_number' => $kpi->revision_number],
                reason: $generalReason,
                actorId: $reviewerId
            );

            // Send notification to employee
            if ($kpi->employee?->user_id) {
                $itemNames = $revisionItems->pluck('name_snapshot')->join(', ');
                SystemNotification::send(
                    userId: $kpi->employee->user_id,
                    title: "Perlu Revisi KPI: {$kpi->period->name}",
                    body: "Supervisor meminta revisi pada indikator: {$itemNames}. Catatan: {$generalReason}",
                    type: 'revision_required',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/my-kpi/{$kpi->id}"
                );
            }
        });
    }

    public function forwardToManager(
        EmployeeKpi $kpi,
        ?string $supervisorNotes = null,
        ?int $reviewerId = null
    ): array {
        $kpi->loadMissing(['items', 'employee.position', 'period']);

        // Check if all items are verified
        $unverified = $kpi->items->filter(function ($item) {
            return !in_array($item->status, ['verified', 'assessed']);
        });

        if ($unverified->isNotEmpty()) {
            $names = $unverified->pluck('name_snapshot')->join(', ');
            throw new Exception("Semua indikator harus diverifikasi sebelum diteruskan ke Manager. Indikator belum selesai: {$names}");
        }

        return DB::transaction(function () use ($kpi, $supervisorNotes, $reviewerId) {
            $beforeStatus = $kpi->status;

            // Recalculate score
            $calcResult = $this->calculationEngine->calculateKpi($kpi, 'review', $reviewerId);

            $kpi->status = 'pending_approval';
            $kpi->verified_at = now();
            $kpi->row_version += 1;
            $kpi->save();

            AuditEvent::log(
                action: 'forward_kpi_to_manager',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: [
                    'status' => 'pending_approval',
                    'final_score' => $kpi->final_score,
                    'rating_code' => $kpi->rating_code,
                    'verified_at' => $kpi->verified_at
                ],
                reason: $supervisorNotes,
                actorId: $reviewerId
            );

            // Notify Manager(s) / Owner
            $managers = User::role(['owner_manager', 'super_admin'])->get();
            foreach ($managers as $manager) {
                SystemNotification::send(
                    userId: $manager->id,
                    title: "KPI Menunggu Approval: {$kpi->employee->name}",
                    body: "Supervisor telah memverifikasi KPI {$kpi->employee->name} ({$kpi->employee->position->name}) dengan skor {$kpi->final_score} ({$kpi->rating_label}). Menunggu persetujuan final Anda.",
                    type: 'kpi_verified',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/manager/approval/{$kpi->id}"
                );
            }

            return [
                'success' => true,
                'message' => 'KPI berhasil diverifikasi dan diteruskan ke Manager untuk persetujuan final.',
                'kpi' => $kpi->fresh(),
                'calculation' => $calcResult,
            ];
        });
    }
}
