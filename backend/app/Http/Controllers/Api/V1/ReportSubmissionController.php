<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportSubmission;
use App\Modules\Reporting\ReportSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $rows = ReportSubmission::query()->where('employee_id', $employee->id)
            ->when($request->integer('period_id'), fn ($query, int $periodId) => $query->where('period_id', $periodId))
            ->orderBy('deadline_at')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function submit(Request $request, int $id, ReportSubmissionService $service): JsonResponse
    {
        $submission = $service->submit(ReportSubmission::findOrFail($id), $request->user());

        return response()->json(['success' => true, 'message' => 'Laporan berhasil disubmit.', 'data' => $submission]);
    }
}
