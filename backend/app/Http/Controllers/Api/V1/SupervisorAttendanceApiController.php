<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\SupervisorAttendanceService;
use App\Support\CapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupervisorAttendanceApiController extends Controller
{
    public function __construct(
        protected SupervisorAttendanceService $attendanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'attendance.team.manage')) {
            return response()->json(['success' => false, 'message' => 'Hanya Supervisor aktif yang dapat mencatat absensi tim.'], 403);
        }

        try {
            $roster = $this->attendanceService->roster(
                $request->user(),
                $request->query('date', now()->toDateString()),
            );

            return response()->json(['success' => true, 'data' => $this->payload($roster)]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'attendance.team.manage')) {
            return response()->json(['success' => false, 'message' => 'Hanya Supervisor aktif yang dapat mencatat absensi tim.'], 403);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'statuses' => ['required', 'array'],
            'notes' => ['sometimes', 'array'],
        ]);

        try {
            $roster = $this->attendanceService->record(
                $request->user(),
                $data['date'],
                $data['statuses'],
                $data['notes'] ?? [],
            );

            return response()->json([
                'success' => true,
                'message' => 'Absensi tim berhasil disimpan.',
                'data' => $this->payload($roster),
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function payload(array $roster): array
    {
        $period = $roster['period'];

        return [
            'date' => $roster['date'],
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
            ],
            'rows' => collect($roster['rows'])->values(),
        ];
    }
}
