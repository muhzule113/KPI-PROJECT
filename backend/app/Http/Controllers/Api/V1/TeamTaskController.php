<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\TeamTaskSummaryService;
use App\Support\CapabilityMatrix;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeamTaskController extends Controller
{
    public function __construct(protected TeamTaskSummaryService $teamTasks) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'kpi.supervisor.review')
            && ! CapabilityMatrix::has($request->user(), 'kpi.manager.approval')) {
            return response()->json(['success' => false, 'message' => 'Ruang kerja tim hanya tersedia untuk Supervisor dan Manager.'], 403);
        }
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->teamTasks->summary($request->user(), $data['date'] ?? null),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
