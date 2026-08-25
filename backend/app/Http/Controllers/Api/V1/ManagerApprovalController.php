<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Modules\Approval\ApprovalService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerApprovalController extends Controller
{
    public function __construct(
        protected ApprovalService $approvalService
    ) {}

    public function queue(): JsonResponse
    {
        $queue = $this->approvalService->getApprovalQueue();

        return response()->json([
            'success' => true,
            'data' => $queue->map(fn($kpi) => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'employee_number' => $kpi->employee->employee_number,
                    'position' => $kpi->employee->position?->name,
                    'branch' => $kpi->employee->branch?->name,
                ],
                'period' => $kpi->period->name,
                'status' => $kpi->status,
                'final_score' => (float) $kpi->final_score,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'verified_at' => $kpi->verified_at?->toIso8601String(),
            ]),
        ]);
    }

    public function detail(string $kpiId): JsonResponse
    {
        $kpi = EmployeeKpi::with([
            'employee.position',
            'employee.branch',
            'period',
            'items.evidences',
            'items.reviewItems',
            'items.assessment.answers',
            'reviews.reviewer',
            'calculationRuns' => fn($q) => $q->latest()->limit(1),
        ])->where('id', $kpiId)->first();

        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        $latestCalc = $kpi->calculationRuns->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $kpi->id,
                'employee' => [
                    'id' => $kpi->employee->id,
                    'name' => $kpi->employee->name,
                    'employee_number' => $kpi->employee->employee_number,
                    'position' => $kpi->employee->position?->name,
                    'branch' => $kpi->employee->branch?->name,
                ],
                'period' => $kpi->period->name,
                'status' => $kpi->status,
                'final_score' => (float) $kpi->final_score,
                'rating_code' => $kpi->rating_code,
                'rating_label' => $kpi->rating_label,
                'verified_at' => $kpi->verified_at?->toIso8601String(),
                'calculation_explanation' => $latestCalc ? $latestCalc->output_snapshot : null,
                'items' => $kpi->items->map(fn($item) => [
                    'id' => $item->id,
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'weight' => (float) $item->weight_snapshot,
                    'target_value' => (float) $item->target_value_snapshot,
                    'target_unit' => $item->target_unit_snapshot,
                    'actual_decimal' => (float) $item->actual_decimal,
                    'achievement_percentage' => (float) $item->achievement_percentage,
                    'weighted_score' => (float) $item->weighted_score,
                    'status' => $item->status,
                    'evidences' => $item->evidences->map(fn($e) => [
                        'id' => $e->id,
                        'file_name' => $e->file_name,
                        'file_url' => asset('storage/' . $e->file_path),
                    ]),
                ]),
            ],
        ]);
    }

    public function approve(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $kpi = EmployeeKpi::where('id', $kpiId)->first();
        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
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
        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
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
