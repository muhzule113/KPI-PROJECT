<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\DashboardDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardDataService $dashboardDataService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->dashboardDataService->payload(
            $user,
            $request->integer('period_id') ?: null,
            $request->integer('position_id') ?: null,
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
