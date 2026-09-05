<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        $employee = $user->employee;
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();

        $data = [
            'active_period' => $activePeriod ? [
                'id' => $activePeriod->id,
                'name' => $activePeriod->name,
                'status' => $activePeriod->status,
                'submission_deadline' => $activePeriod->submission_deadline->toIso8601String(),
                'review_deadline' => $activePeriod->review_deadline->toIso8601String(),
                'approval_deadline' => $activePeriod->approval_deadline->toIso8601String(),
            ] : null,
        ];

        // 1. Employee stats
        if ($employee && $activePeriod) {
            $myKpi = EmployeeKpi::with(['items.evidences'])
                ->where('period_id', $activePeriod->id)
                ->where('employee_id', $employee->id)
                ->first();

            $data['my_kpi'] = $myKpi ? [
                'id' => $myKpi->id,
                'status' => $myKpi->status,
                'progress_percentage' => (float) $myKpi->progress_percentage,
                'final_score' => $myKpi->final_score !== null ? (float) $myKpi->final_score : null,
                'rating_code' => $myKpi->rating_code,
                'rating_label' => $myKpi->rating_label,
                'total_items' => $myKpi->items->count(),
                'filled_items' => $myKpi->items->filter(fn($i) => $i->actual_decimal !== null || $i->actual_json !== null)->count(),
            ] : null;
        }

        // 2. Supervisor stats
        if ($user->hasRole('supervisor') && !$user->hasRole('super_admin') && $employee) {
            $teamKpis = EmployeeKpi::where('supervisor_id_snapshot', $employee->id)
                ->where('period_id', $activePeriod?->id)
                ->get();

            $data['supervisor_queue'] = [
                'total_team_members' => $teamKpis->count(),
                'submitted_count' => $teamKpis->where('status', 'submitted')->count(),
                'under_review_count' => $teamKpis->where('status', 'under_review')->count(),
                'revision_count' => $teamKpis->where('status', 'revision_required')->count(),
                'verified_count' => $teamKpis->whereIn('status', ['verified', 'pending_approval', 'approved', 'locked'])->count(),
            ];
        }

        // 3. Manager / Executive stats
        if ($user->hasRole(['owner_manager', 'super_admin', 'kpi_admin'])) {
            $allKpis = EmployeeKpi::where('period_id', $activePeriod?->id)->get();
            $totalCount = $allKpis->count();
            $approvedCount = $allKpis->whereIn('status', ['approved', 'locked'])->count();
            $completionRate = $totalCount > 0 ? round(($approvedCount / $totalCount) * 100, 1) : 0.0;
            $avgScore = $allKpis->whereNotNull('final_score')->avg('final_score');

            $data['executive_overview'] = [
                'total_employees' => $totalCount,
                'completion_rate' => $completionRate,
                'pending_approval_count' => $allKpis->where('status', 'pending_approval')->count(),
                'average_score' => $avgScore !== null ? round((float) $avgScore, 2) : 0.0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
