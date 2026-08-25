<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiPeriod;
use Illuminate\Http\JsonResponse;

class PeriodApiController extends Controller
{
    public function index(): JsonResponse
    {
        $periods = KpiPeriod::orderByDesc('year')->orderByDesc('month')->get();

        return response()->json([
            'success' => true,
            'data' => $periods->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
            ]),
        ]);
    }
}
