<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Modules\Review\ReviewService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisorReviewController extends Controller
{
    public function __construct(
        protected ReviewService $reviewService
    ) {}

    public function queue(Request $request): JsonResponse
    {
        $queue = $this->reviewService->getReviewQueue($request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $queue->map(fn($kpi) => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'employee_number' => $kpi->employee->employee_number,
                    'position' => $kpi->employee->position?->name,
                ],
                'period' => $kpi->period->name,
                'status' => $kpi->status,
                'progress_percentage' => (float) $kpi->progress_percentage,
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'submitted_at' => $kpi->submitted_at?->toIso8601String(),
                'total_items' => $kpi->items->count(),
                'verified_items' => $kpi->items->whereIn('status', ['verified', 'assessed'])->count(),
            ]),
        ]);
    }

    public function detail(Request $request, string $kpiId): JsonResponse
    {
        $kpi = EmployeeKpi::with([
            'employee.position',
            'employee.branch',
            'period',
            'items.evidences',
            'items.reviewItems',
            'items.assessment.answers',
        ])->where('id', $kpiId)->first();

        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'position' => $kpi->employee->position?->name,
                    'branch' => $kpi->employee->branch?->name,
                ],
                'period' => [
                    'id' => $kpi->period->id,
                    'name' => $kpi->period->name,
                    'review_deadline' => $kpi->period->review_deadline->toIso8601String(),
                ],
                'status' => $kpi->status,
                'progress_percentage' => (float) $kpi->progress_percentage,
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'rating_label' => $kpi->rating_label,
                'revision_number' => $kpi->revision_number,
                'items' => $kpi->items->map(fn($item) => [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'weight' => (float) $item->weight_snapshot,
                    'target_value' => (float) $item->target_value_snapshot,
                    'target_unit' => $item->target_unit_snapshot,
                    'formula' => $item->formula_key_snapshot,
                    'source_type' => $item->source_type_snapshot,
                    'evidence_required' => (bool) $item->evidence_req_snapshot,
                    'actual_decimal' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                    'achievement_percentage' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                    'weighted_score' => $item->weighted_score !== null ? (float) $item->weighted_score : null,
                    'status' => $item->status,
                    'rubric' => $item->rubric_snapshot,
                    'assessment' => $item->assessment ? [
                        'score_points' => (float) $item->assessment->score_points,
                        'total_points' => (float) $item->assessment->total_points,
                        'calculated_achievement' => (float) $item->assessment->calculated_achievement,
                        'answers' => $item->assessment->answers->map(fn($a) => [
                            'criterion_id' => $a->criterion_id,
                            'criterion_text' => $a->criterion_text,
                            'is_fulfilled' => $a->is_fulfilled,
                            'points_earned' => (float) $a->points_earned,
                        ]),
                    ] : null,
                    'evidences' => $item->evidences->map(fn($e) => [
                        'id' => $e->id,
                        'file_name' => $e->file_name,
                        'file_url' => asset('storage/' . $e->file_path),
                        'file_size' => $e->file_size,
                    ]),
                ]),
            ],
        ]);
    }

    public function verifyItem(Request $request, string $kpiId, string $itemId): JsonResponse
    {
        $request->validate([
            'decision' => 'required|in:valid,revision_required',
            'note' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);

        $item = EmployeeKpiItem::where('id', $itemId)->where('employee_kpi_id', $kpiId)->first();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item tidak ditemukan.'], 404);
        }

        try {
            $updated = $this->reviewService->verifyItem(
                item: $item,
                decision: $request->input('decision'),
                note: $request->input('note'),
                reason: $request->input('reason'),
                reviewerId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Keputusan review item berhasil disimpan.',
                'data' => [
                    'item_id' => $updated->id,
                    'status' => $updated->status,
                    'achievement' => $updated->achievement_percentage,
                    'weighted_score' => $updated->weighted_score,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function submitRubric(Request $request, string $kpiId, string $itemId): JsonResponse
    {
        $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.criterion_id' => 'nullable|integer',
            'answers.*.criterion_text' => 'required|string',
            'answers.*.points' => 'required|numeric',
            'answers.*.is_fulfilled' => 'required|boolean',
            'answers.*.notes' => 'nullable|string',
        ]);

        $item = EmployeeKpiItem::where('id', $itemId)->where('employee_kpi_id', $kpiId)->first();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item tidak ditemukan.'], 404);
        }

        try {
            $assessment = $this->reviewService->submitRubricAssessment(
                item: $item,
                answers: $request->input('answers'),
                reviewerId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Penilaian rubrik checklist berhasil disimpan.',
                'data' => [
                    'item_id' => $item->id,
                    'score_points' => $assessment->score_points,
                    'total_points' => $assessment->total_points,
                    'calculated_achievement' => $assessment->calculated_achievement,
                    'weighted_score' => $item->fresh()->weighted_score,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function requestRevision(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        $kpi = EmployeeKpi::where('id', $kpiId)->first();
        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        try {
            $this->reviewService->requestRevision($kpi, $request->input('reason'), $request->user()->id);
            return response()->json([
                'success' => true,
                'message' => 'Permintaan revisi berhasil dikirimkan ke karyawan.',
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function forward(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $kpi = EmployeeKpi::where('id', $kpiId)->first();
        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        try {
            $result = $this->reviewService->forwardToManager($kpi, $request->input('notes'), $request->user()->id);
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'kpi_id' => $result['kpi']->id,
                    'status' => $result['kpi']->status,
                    'final_score' => $result['kpi']->final_score,
                    'rating_label' => $result['kpi']->rating_label,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
