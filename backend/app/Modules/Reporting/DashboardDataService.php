<?php

namespace App\Modules\Reporting;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\Position;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\CapabilityMatrix;
use App\Support\KpiVisibility;
use Illuminate\Database\Eloquent\Builder;

final class DashboardDataService
{
    public function payload(User $user, ?int $periodId = null, ?int $positionId = null): array
    {
        $user->loadMissing(['employee.position', 'employee.branch', 'roles']);

        $period = $periodId
            ? KpiPeriod::query()->find($periodId)
            : null;
        $period ??= KpiPeriod::query()
            ->where('status', 'OPEN')
            ->orderByDesc('id')
            ->first()
            ?? KpiPeriod::query()
                ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                ->orderByDesc('id')
                ->first();

        $kpis = $this->kpiQuery($user, $period, $positionId);
        $scoredKpis = KpiVisibility::visibleScores(clone $kpis, $user);
        $evaluatedKpis = (clone $scoredKpis)->whereNotNull('final_score');
        $averageScore = (clone $evaluatedKpis)->avg('final_score');

        $data = [
            'active_period' => $this->periodPayload($period),
            'filters' => $this->filterPayload($period, $positionId),
            'metrics' => [
                'total' => (clone $kpis)->count(),
                'average' => $averageScore !== null ? round((float) $averageScore, 1) : null,
                'achieved' => (clone $scoredKpis)
                    ->whereNotNull('final_score')
                    ->where('final_score', '>=', 80)
                    ->count(),
                'not_achieved' => (clone $scoredKpis)
                    ->whereNotNull('final_score')
                    ->where('final_score', '<', 80)
                    ->count(),
                'evaluated' => (clone $evaluatedKpis)->count(),
            ],
            'trend' => $this->trendPayload($user, $positionId),
            'top_performers' => $this->topPerformersPayload($user, $period, $positionId),
            'recent_kpis' => $this->recentKpisPayload($user, $period, $positionId),
            'attention_kpis' => $this->attentionKpisPayload($user, $period, $positionId),
            'notifications' => $this->notificationsPayload($user),
        ];

        $employee = $user->employee;
        if ($employee && $period) {
            $myKpi = EmployeeKpi::with(['items.evidences'])
                ->where('period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();

            $data['my_kpi'] = $myKpi ? [
                'id' => $myKpi->id,
                'status' => $myKpi->status,
                'progress_percentage' => (float) $myKpi->progress_percentage,
                'score_visible' => KpiVisibility::published($period),
                'final_score' => KpiVisibility::published($period) && $myKpi->final_score !== null ? (float) $myKpi->final_score : null,
                'rating_code' => KpiVisibility::published($period) ? $myKpi->rating_code : null,
                'rating_label' => KpiVisibility::published($period) ? $myKpi->rating_label : null,
                'total_items' => $myKpi->items->count(),
                'filled_items' => $myKpi->items->filter(fn ($item) => $item->actual_decimal !== null || $item->actual_json !== null)->count(),
            ] : null;
        } else {
            $data['my_kpi'] = null;
        }

        if ($user->hasRole('supervisor') && ! $user->hasRole('super_admin') && $employee) {
            $teamKpis = $this->scopedKpis($user)->where('supervisor_id_snapshot', $employee->id)
                ->where('period_id', $period?->id)
                ->get();

            $data['supervisor_queue'] = [
                'total_team_members' => $teamKpis->count(),
                'submitted_count' => $teamKpis->where('status', 'submitted')->count(),
                'under_review_count' => $teamKpis->where('status', 'under_review')->count(),
                'revision_count' => $teamKpis->where('status', 'revision_required')->count(),
                'verified_count' => $teamKpis->whereIn('status', ['verified', 'pending_approval', 'approved', 'locked'])->count(),
            ];
        }

        if (CapabilityMatrix::has($user, 'reports.view')) {
            $allKpis = (clone $kpis)->get();
            $totalCount = $allKpis->count();
            $approvedCount = $allKpis->whereIn('status', ['approved', 'locked'])->count();

            $data['executive_overview'] = [
                'total_employees' => $totalCount,
                'completion_rate' => $totalCount > 0 ? round(($approvedCount / $totalCount) * 100, 1) : 0.0,
                'pending_approval_count' => $allKpis->where('status', 'pending_approval')->count(),
                'average_score' => $averageScore !== null ? round((float) $averageScore, 2) : null,
            ];
        }

        return $data;
    }

    private function kpiQuery(User $user, ?KpiPeriod $period, ?int $positionId = null): Builder
    {
        $query = $this->scopedKpis($user, $positionId);

        return $period ? $query->where('period_id', $period->getKey()) : $query->whereIn('period_id', []);
    }

    private function scopedKpis(User $user, ?int $positionId = null): Builder
    {
        return KpiVisibility::applyScope(EmployeeKpi::query(), $user)
            ->when($positionId, fn (Builder $query) => $query->where('position_id_snapshot', $positionId));
    }

    private function trendPayload(User $user, ?int $positionId = null): array
    {
        $periods = KpiPeriod::query()
            ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        if ($periods->isEmpty()) {
            return [];
        }

        $averages = KpiVisibility::visibleScores($this->scopedKpis($user, $positionId), $user)
            ->whereIn('period_id', $periods->pluck('id'))
            ->whereNotNull('final_score')
            ->select('period_id')
            ->selectRaw('AVG(final_score) AS average_score')
            ->groupBy('period_id')
            ->pluck('average_score', 'period_id');

        return $periods->map(fn (KpiPeriod $period) => [
            'id' => $period->getKey(),
            'label' => $period->name,
            'average' => $averages->has($period->getKey())
                ? round((float) $averages->get($period->getKey()), 1)
                : null,
        ])->all();
    }

    private function topPerformersPayload(User $user, ?KpiPeriod $period, ?int $positionId = null): array
    {
        if (! $period) {
            return [];
        }

        $ranked = KpiVisibility::visibleScores($this->kpiQuery($user, $period, $positionId), $user)
            ->whereNotNull('final_score')
            ->select(['employee_id', 'position_id_snapshot'])
            ->selectRaw('AVG(final_score) AS average_score')
            ->groupBy('employee_id', 'position_id_snapshot')
            ->orderByDesc('average_score')
            ->limit(5)
            ->get();

        $employees = Employee::query()
            ->whereIn('id', $ranked->pluck('employee_id'))
            ->get()
            ->keyBy('id');
        $positions = Position::query()
            ->whereIn('id', $ranked->pluck('position_id_snapshot')->filter())
            ->pluck('name', 'id');

        return $ranked->map(function (EmployeeKpi $row) use ($employees, $positions): array {
            $employee = $employees->get($row->employee_id);

            return [
                'employee_id' => $row->employee_id,
                'name' => $employee?->name ?? 'Karyawan tidak ditemukan',
                'position' => $positions->get($row->position_id_snapshot),
                'average' => round((float) $row->average_score, 1),
            ];
        })->all();
    }

    private function recentKpisPayload(User $user, ?KpiPeriod $period, ?int $positionId = null): array
    {
        if (! $period) {
            return [];
        }

        return $this->kpiQuery($user, $period, $positionId)
            ->with(['employee', 'positionSnapshot'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeKpi $kpi) => $this->kpiPayload($kpi, $user))
            ->all();
    }

    private function attentionKpisPayload(User $user, ?KpiPeriod $period, ?int $positionId = null): array
    {
        if (! $period) {
            return [];
        }

        return $this->kpiQuery($user, $period, $positionId)
            ->with(['employee', 'positionSnapshot'])
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereIn('status', ['revision_required', 'pending_approval'])
                    ->orWhere(function (Builder $query) use ($user): void {
                        KpiVisibility::visibleScores($query, $user)->whereNotNull('final_score')->where('final_score', '<', 80);
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeKpi $kpi) => $this->kpiPayload($kpi, $user))
            ->all();
    }

    private function kpiPayload(EmployeeKpi $kpi, User $user): array
    {
        return [
            'id' => $kpi->getKey(),
            'name' => $kpi->employee?->name ?? 'Karyawan tidak ditemukan',
            'position' => $kpi->positionSnapshot?->name,
            'status' => $kpi->status,
            'score' => KpiVisibility::scoreVisible($user, $kpi) && $kpi->final_score !== null ? (float) $kpi->final_score : null,
            'progress' => $kpi->progress_percentage !== null ? (float) $kpi->progress_percentage : null,
            'updated_at' => $kpi->updated_at?->toIso8601String(),
        ];
    }

    private function notificationsPayload(User $user): array
    {
        return SystemNotification::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (SystemNotification $notification) => $notification->visiblePayload($user))
            ->filter()->values()
            ->all();
    }

    private function filterPayload(?KpiPeriod $period, ?int $positionId): array
    {
        return [
            'period_id' => $period?->getKey(),
            'position_id' => $positionId,
            'periods' => KpiPeriod::query()
                ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->get(['id', 'name'])
                ->map(fn (KpiPeriod $item): array => ['value' => (string) $item->getKey(), 'label' => $item->name])
                ->all(),
            'positions' => Position::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Position $item): array => ['value' => (string) $item->getKey(), 'label' => $item->name])
                ->all(),
        ];
    }

    private function periodPayload(?KpiPeriod $period): ?array
    {
        if (! $period) {
            return null;
        }

        return [
            'id' => $period->getKey(),
            'name' => $period->name,
            'status' => $period->status,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'submission_deadline' => $period->submission_deadline?->toIso8601String(),
            'review_deadline' => $period->review_deadline?->toIso8601String(),
            'approval_deadline' => $period->approval_deadline?->toIso8601String(),
        ];
    }
}
