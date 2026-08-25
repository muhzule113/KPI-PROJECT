<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiPeriod;
use App\Modules\Assessment\AssessmentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyKpiController extends Controller
{
    public function __construct(
        protected AssessmentService $assessmentService
    ) {}

    public function active(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Profil karyawan tidak ditemukan.'], 404);
        }

        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Tidak ada periode KPI yang sedang aktif.'], 404);
        }

        $kpi = EmployeeKpi::with([
            'period',
            'items.evidences',
            'items.reviewItems',
            'supervisorSnapshot',
        ])
        ->where('period_id', $activePeriod->id)
        ->where('employee_id', $employee->id)
        ->first();

        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'Snapshot KPI Anda belum digenerate untuk periode ini.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatKpiResponse($kpi),
        ]);
    }

    public function getItem(Request $request, string $itemId): JsonResponse
    {
        $employee = $request->user()->employee;
        $item = EmployeeKpiItem::with(['evidences', 'reviewItems', 'employeeKpi.period'])
            ->where('id', $itemId)
            ->first();

        if (!$item || $item->employeeKpi->employee_id !== $employee?->id) {
            return response()->json(['success' => false, 'message' => 'Indikator KPI tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->id,
                'kpi_id' => $item->employee_kpi_id,
                'code' => $item->definition_code_snapshot,
                'name' => $item->name_snapshot,
                'weight' => (float) $item->weight_snapshot,
                'target_value' => (float) $item->target_value_snapshot,
                'target_unit' => $item->target_unit_snapshot,
                'target_json' => $item->target_json_snapshot,
                'formula' => $item->formula_key_snapshot,
                'source_type' => $item->source_type_snapshot,
                'evidence_required' => (bool) $item->evidence_req_snapshot,
                'rubric' => $item->rubric_snapshot,
                'actual_decimal' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                'actual_json' => $item->actual_json,
                'status' => $item->status,
                'achievement_percentage' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                'weighted_score' => $item->weighted_score !== null ? (float) $item->weighted_score : null,
                'evidences' => $item->evidences->map(fn($e) => [
                    'id' => $e->id,
                    'file_name' => $e->file_name,
                    'file_url' => asset('storage/' . $e->file_path),
                    'file_size' => $e->file_size,
                    'created_at' => $e->created_at->toIso8601String(),
                ]),
                'latest_review' => $item->reviewItems->last() ? [
                    'decision' => $item->reviewItems->last()->decision,
                    'supervisor_note' => $item->reviewItems->last()->supervisor_note,
                    'reason' => $item->reviewItems->last()->reason,
                ] : null,
            ],
        ]);
    }

    public function saveDraft(Request $request, string $itemId): JsonResponse
    {
        $request->validate([
            'actual_decimal' => 'nullable|numeric',
            'actual_json' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $employee = $request->user()->employee;
        $item = EmployeeKpiItem::where('id', $itemId)->first();

        if (!$item || $item->employeeKpi->employee_id !== $employee?->id) {
            return response()->json(['success' => false, 'message' => 'Indikator KPI tidak ditemukan.'], 404);
        }

        try {
            $updated = $this->assessmentService->saveItemDraft(
                item: $item,
                actualDecimal: $request->input('actual_decimal') !== null ? (float) $request->actual_decimal : null,
                actualJson: $request->input('actual_json'),
                notes: $request->input('notes'),
                userId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Draft nilai aktual berhasil disimpan.',
                'data' => [
                    'item_id' => $updated->id,
                    'actual_decimal' => (float) $updated->actual_decimal,
                    'status' => $updated->status,
                    'achievement_percentage' => $updated->achievement_percentage,
                    'weighted_score' => $updated->weighted_score,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function uploadEvidence(Request $request, string $itemId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf,xlsx,docx',
            'description' => 'nullable|string|max:255',
        ]);

        $employee = $request->user()->employee;
        $item = EmployeeKpiItem::where('id', $itemId)->first();

        if (!$item || $item->employeeKpi->employee_id !== $employee?->id) {
            return response()->json(['success' => false, 'message' => 'Indikator KPI tidak ditemukan.'], 404);
        }

        try {
            $evidence = $this->assessmentService->uploadEvidence(
                item: $item,
                file: $request->file('file'),
                description: $request->input('description'),
                userId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Bukti file berhasil diunggah.',
                'data' => [
                    'id' => $evidence->id,
                    'file_name' => $evidence->file_name,
                    'file_url' => asset('storage/' . $evidence->file_path),
                    'file_size' => $evidence->file_size,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function submit(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();

        if (!$employee || !$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid.'], 400);
        }

        $kpi = EmployeeKpi::where('period_id', $activePeriod->id)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        try {
            $result = $this->assessmentService->submitKpi($kpi, $request->user()->id);
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $this->formatKpiResponse($result['kpi']),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Profil tidak ditemukan.'], 404);
        }

        $history = EmployeeKpi::with(['period'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'locked', 'published'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history->map(fn($h) => [
                'id' => $h->id,
                'period_name' => $h->period->name,
                'status' => $h->status,
                'final_score' => (float) $h->final_score,
                'rating_code' => $h->rating_code,
                'rating_label' => $h->rating_label,
                'approved_at' => $h->approved_at?->toIso8601String(),
            ]),
        ]);
    }

    protected function formatKpiResponse(EmployeeKpi $kpi): array
    {
        return [
            'id' => $kpi->id,
            'period' => [
                'id' => $kpi->period->id,
                'name' => $kpi->period->name,
                'submission_deadline' => $kpi->period->submission_deadline->toIso8601String(),
            ],
            'status' => $kpi->status,
            'progress_percentage' => (float) $kpi->progress_percentage,
            'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
            'rating_code' => $kpi->rating_code,
            'rating_label' => $kpi->rating_label,
            'revision_number' => $kpi->revision_number,
            'supervisor' => $kpi->supervisorSnapshot?->name,
            'items' => $kpi->items->map(fn($item) => [
                'id' => $item->id,
                'code' => $item->definition_code_snapshot,
                'name' => $item->name_snapshot,
                'weight' => (float) $item->weight_snapshot,
                'target_value' => (float) $item->target_value_snapshot,
                'target_unit' => $item->target_unit_snapshot,
                'target_json' => $item->target_json_snapshot,
                'formula' => $item->formula_key_snapshot,
                'source_type' => $item->source_type_snapshot,
                'evidence_required' => (bool) $item->evidence_req_snapshot,
                'has_evidence' => $item->evidences->isNotEmpty(),
                'status' => $item->status,
                'actual_decimal' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                'achievement_percentage' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                'weighted_score' => $item->weighted_score !== null ? (float) $item->weighted_score : null,
                'rubric' => $item->rubric_snapshot,
            ]),
        ];
    }
}
