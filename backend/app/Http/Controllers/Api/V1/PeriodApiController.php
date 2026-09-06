<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $periods = KpiPeriod::query()
            ->when(
                ! $request->boolean('include_history'),
                fn ($query) => $query->where('status', 'OPEN'),
            )
            ->when(
                $request->boolean('include_history'),
                fn ($query) => $query->whereNotIn('status', ['DRAFT', 'CANCELLED']),
            )
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $periods->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
            ]),
        ]);
    }
}
