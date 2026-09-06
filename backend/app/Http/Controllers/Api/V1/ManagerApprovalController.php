<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Modules\Approval\ApprovalService;
use App\Modules\Review\ReviewService;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ManagerApprovalController extends Controller
{
    public function __construct(
        protected ApprovalService $approvalService,
        protected ReviewService $reviewService
    ) {}

    public function queue(Request $request): JsonResponse
    {
        $queue = $this->approvalService->getApprovalQueue($request->user());

        return response()->json([
            'success' => true,
            'data' => $queue->map(fn ($kpi) => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'employee_number' => $kpi->employee->employee_number,
                    'position' => $kpi->positionSnapshot?->name,
                    'branch' => $kpi->branchSnapshot?->name,
                ],
                'period' => $kpi->period->name,
                'status' => $kpi->status,
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'verified_at' => $kpi->verified_at?->toIso8601String(),
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
            'reviews.reviewer',
            'calculationRuns' => fn ($q) => $q->latest()->limit(1),
        ])->where('id', $kpiId)->first();

        if (! $kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        if (! KpiWorkflow::canApproveKpi($request->user(), $kpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang mengakses KPI ini.'], 403);
        }

        $actions = KpiWorkflow::availableActions($request->user(), $kpi);
        $canAssess = in_array('decide', $actions, true);

        $latestCalc = $kpi->calculationRuns->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'employee_number' => $kpi->employee->employee_number,
                    'position' => $kpi->positionSnapshot?->name,
                    'branch' => $kpi->branchSnapshot?->name,
                ],
                'period' => $kpi->period->name,
                'status' => $kpi->status,
                'available_actions' => $actions,
                'is_supervisor_kpi' => $kpi->isSupervisorKpi(),
                'can_assess' => $canAssess,
                'can_approve' => in_array('approve', $actions, true),
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'verified_at' => $kpi->verified_at?->toIso8601String(),
                'calculation_explanation' => $latestCalc ? $latestCalc->output_snapshot : null,
                'items' => $kpi->items->map(fn ($item) => [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'weight' => (float) $item->weight_snapshot,
                    'target_value' => (float) $item->target_value_snapshot,
                    'target_unit' => $item->target_unit_snapshot,
                    'formula' => $item->formula_key_snapshot,
                    'source_type' => $item->source_type_snapshot,
                    'rubric' => $item->rubric_snapshot,
                    'actual_decimal' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                    'achievement_percentage' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                    'weighted_score' => $item->weighted_score !== null ? (float) $item->weighted_score : null,
                    'status' => $item->status,
                    'manager_decision' => $item->manager_decision,
                    'manager_note' => $item->manager_note,
                    'assessment' => $item->assessment ? [
                        'assessed_by' => $item->assessment->assessed_by,
                        'score_points' => (float) $item->assessment->score_points,
                        'total_points' => (float) $item->assessment->total_points,
                        'calculated_achievement' => (float) $item->assessment->calculated_achievement,
                        'answers' => $item->assessment->answers->map(fn ($answer) => [
                            'criterion_id' => $answer->criterion_id,
                            'is_fulfilled' => (bool) $answer->is_fulfilled,
                        ])->values()->all(),
                    ] : null,
                    'evidences' => $item->evidences->map(fn ($e) => [
                        'id' => $e->id,
                        'file_name' => $e->file_name,
                        'file_url' => URL::temporarySignedRoute('api.v1.kpi.evidence.download', now()->addMinutes(5), ['evidenceId' => $e->id]),
                    ]),
                ]),
            ],
        ]);
    }

    public function assessItem(Request $request, string $kpiId, string $itemId): JsonResponse
    {
        $request->validate([
            'decision' => 'required|in:valid,needs_correction,data_exception',
            'actual_decimal' => 'prohibited',
            'note' => 'nullable|string|max:2000',
            'evidence' => 'nullable|array',
            'evidence.*.type' => 'required_with:evidence|string|max:30',
            'evidence.*.reference' => 'required_with:evidence|string|max:500',
        ]);

        $item = EmployeeKpiItem::with('employeeKpi.employee')
            ->where('id', $itemId)
            ->where('employee_kpi_id', $kpiId)
            ->first();
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Item tidak ditemukan.'], 404);
        }
        if (! KpiWorkflow::canManageKpi($request->user(), $item->employeeKpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang menilai KPI ini.'], 403);
        }

        try {
            $updated = $this->approvalService->decideItem(
                item: $item,
                decision: $request->input('decision'),
                note: $request->input('note'),
                evidence: $request->input('evidence', []),
                assessorId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Penilaian Manager berhasil disimpan.',
                'data' => [
                    'item_id' => $updated->id,
                    'status' => $updated->status,
                    'actual_decimal' => $updated->actual_decimal,
                    'achievement_percentage' => $updated->achievement_percentage,
                    'weighted_score' => $updated->weighted_score,
                    'manager_decision' => $updated->manager_decision,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function assessRubric(Request $request, string $kpiId, string $itemId): JsonResponse
    {
        $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.criterion_id' => 'required|integer',
            'answers.*.is_fulfilled' => 'required|boolean',
            'answers.*.notes' => 'nullable|string',
            'decision' => 'required|in:valid,needs_correction,data_exception',
            'note' => 'nullable|string|max:2000',
        ]);

        $item = EmployeeKpiItem::with('employeeKpi.employee')
            ->where('id', $itemId)
            ->where('employee_kpi_id', $kpiId)
            ->first();
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Item tidak ditemukan.'], 404);
        }
        if (! KpiWorkflow::canManageKpi($request->user(), $item->employeeKpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang menilai KPI ini.'], 403);
        }

        try {
            $assessment = $this->reviewService->submitRubricAssessment(
                item: $item,
                answers: $request->input('answers'),
                reviewerId: $request->user()->id,
                managerDecision: $request->input('decision'),
                managerNote: $request->input('note')
            );

            return response()->json([
                'success' => true,
                'message' => 'Penilaian rubric Manager berhasil disimpan.',
                'data' => [
                    'item_id' => $item->id,
                    'status' => $item->fresh()->status,
                    'score_points' => $assessment->score_points,
                    'total_points' => $assessment->total_points,
                    'calculated_achievement' => $assessment->calculated_achievement,
                    'weighted_score' => $item->fresh()->weighted_score,
                    'manager_decision' => $item->fresh()->manager_decision,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $kpi = EmployeeKpi::where('id', $kpiId)->first();
        if (! $kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        if (! KpiWorkflow::canApproveKpi($request->user(), $kpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang menyetujui KPI ini.'], 403);
        }

        try {
            $result = $this->approvalService->approve($kpi, $request->input('note'), $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'kpi_id' => $result['kpi']->id,
                    'status' => $result['kpi']->status,
                    'final_score' => $result['kpi']->final_score,
                    'rating_label' => $result['kpi']->rating_label,
                    'approved_at' => $result['kpi']->approved_at?->toIso8601String(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function return(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $kpi = EmployeeKpi::where('id', $kpiId)->first();
        if (! $kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        if (! KpiWorkflow::canApproveKpi($request->user(), $kpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang mengembalikan KPI ini.'], 403);
        }

        try {
            $result = $this->approvalService->return($kpi, $request->input('reason'), $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'kpi_id' => $result['kpi']->id,
                    'status' => $result['kpi']->status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
