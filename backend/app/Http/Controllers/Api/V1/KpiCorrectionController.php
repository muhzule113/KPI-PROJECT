<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\KpiCorrectionRequest;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Support\KpiVisibility;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiCorrectionController extends Controller
{
    public function __construct(
        protected ApprovalService $approvalService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('employee');
        $query = KpiCorrectionRequest::with([
            'employeeKpi.employee.position',
            'employeeKpi.period',
            'requester',
            'approver',
        ])->latest();

        $query->whereHas('employeeKpi', fn ($kpis) => KpiVisibility::applyScope($kpis, $user));

        return response()->json([
            'success' => true,
            'data' => $query->limit(100)->get()->map(fn (KpiCorrectionRequest $correction) => $this->format($correction, $user)),
        ]);
    }

    public function request(Request $request, string $kpiId): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string'],
            'items.*.actual' => ['required', 'numeric'],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*.type' => ['required', 'string', 'max:30'],
            'evidence.*.reference' => ['required', 'string', 'max:500'],
        ]);

        $kpi = EmployeeKpi::with('employee')->whereKey($kpiId)->first();
        if (! $kpi) {
            return response()->json(['success' => false, 'message' => 'KPI tidak ditemukan.'], 404);
        }

        if (! KpiWorkflow::canRequestCorrection($request->user(), $kpi)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang mengajukan koreksi KPI ini.'], 403);
        }

        try {
            $correction = $this->approvalService->requestCorrection(
                kpi: $kpi,
                reason: $request->string('reason')->toString(),
                afterData: [
                    'items' => $request->input('items'),
                    'evidence' => $request->input('evidence'),
                ],
                requesterId: $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Permintaan koreksi berhasil diajukan.',
                'data' => $this->format($correction->load(['employeeKpi.employee.position', 'employeeKpi.period', 'requester']), $request->user()),
            ], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, int $correctionId): JsonResponse
    {
        $correction = KpiCorrectionRequest::with('employeeKpi.employee')
            ->whereKey($correctionId)
            ->first();
        if (! $correction) {
            return response()->json(['success' => false, 'message' => 'Permintaan koreksi tidak ditemukan.'], 404);
        }

        try {
            $this->approvalService->approveCorrection($correction, $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Koreksi KPI berhasil diterapkan.',
                'data' => ['correction_id' => $correction->id, 'status' => 'applied'],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $correctionId): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $correction = KpiCorrectionRequest::with('employeeKpi.employee')
            ->whereKey($correctionId)
            ->first();
        if (! $correction) {
            return response()->json(['success' => false, 'message' => 'Permintaan koreksi tidak ditemukan.'], 404);
        }

        try {
            $this->approvalService->rejectCorrection(
                request: $correction,
                rejectorId: $request->user()->id,
                rejectionReason: $request->input('reason'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Permintaan koreksi ditolak.',
                'data' => ['correction_id' => $correction->id, 'status' => 'rejected'],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function format(KpiCorrectionRequest $correction, User $user): array
    {
        return [
            'id' => $correction->id,
            'status' => $correction->status,
            'reason' => $correction->reason,
            'rejection_reason' => $correction->rejection_reason,
            'row_version_snapshot' => $correction->row_version_snapshot,
            'requested_by' => $correction->requested_by,
            'approved_by' => $correction->approved_by,
            'requested_at' => $correction->created_at?->toIso8601String(),
            'applied_at' => $correction->applied_at?->toIso8601String(),
            'employee_kpi' => $correction->employeeKpi ? [
                'id' => $correction->employeeKpi->id,
                'employee' => [
                    'id' => $correction->employeeKpi->employee?->id,
                    'name' => $correction->employeeKpi->employee?->name,
                    'position' => $correction->employeeKpi->employee?->position?->name,
                ],
                'period' => $correction->employeeKpi->period?->name,
                'status' => $correction->employeeKpi->status,
                'revision_number' => $correction->employeeKpi->revision_number,
            ] : null,
            'before' => KpiVisibility::scoreVisible($user, $correction->employeeKpi) ? $correction->before_json : null,
            'after' => KpiVisibility::scoreVisible($user, $correction->employeeKpi) ? $correction->after_json : null,
        ];
    }
}
