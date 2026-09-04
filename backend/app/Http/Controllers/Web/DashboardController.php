<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\Position;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\MenuAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Index', $this->payload($request));
    }

    /**
     * Return the dashboard data used by the Inertia app.
     *
     * @return array<string, mixed>
     */
    public function payload(Request $request): array
    {
        /** @var User $user */
        $user = $request->user()->loadMissing([
            'employee.position',
            'employee.branch',
            'roles',
        ]);

        $periodId = $request->integer('period_id') ?: null;
        $positionId = $request->integer('position_id') ?: null;
        $activePeriod = $periodId ? KpiPeriod::query()->find($periodId) : null;
        $activePeriod ??= KpiPeriod::query()
            ->where('status', 'OPEN')
            ->orderByDesc('id')
            ->first()
            ?? KpiPeriod::query()
                ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                ->orderByDesc('id')
                ->first();

        $kpis = $this->kpiQuery($user, $activePeriod, $positionId);
        $evaluatedKpis = (clone $kpis)->whereNotNull('final_score');
        $averageScore = (clone $evaluatedKpis)->avg('final_score');

        $metrics = [
            'total' => (clone $kpis)->count(),
            'average' => $averageScore !== null ? round((float) $averageScore, 1) : null,
            'achieved' => (clone $kpis)
                ->whereNotNull('final_score')
                ->where('final_score', '>=', 80)
                ->count(),
            'not_achieved' => (clone $kpis)
                ->whereNotNull('final_score')
                ->where('final_score', '<', 80)
                ->count(),
            'evaluated' => (clone $evaluatedKpis)->count(),
        ];

        $canExport = MenuAccess::can($user, ['owner_manager', 'super_admin', 'auditor'], []);

        return [
            'activePeriod' => $this->periodPayload($activePeriod),
            'navigation' => AdminNavigation::for($user),
            'canExport' => $canExport,
            'filters' => $canExport ? $this->filterPayload($activePeriod, $positionId) : [],
            'metrics' => $metrics,
            'trend' => $this->trendPayload($user, $positionId),
            'topPerformers' => $this->topPerformersPayload($user, $activePeriod, $positionId),
            'recentKpis' => $this->recentKpisPayload($user, $activePeriod, $positionId),
            'attentionKpis' => $this->attentionKpisPayload($user, $activePeriod, $positionId),
            'notifications' => $this->notificationsPayload($user),
        ];
    }

    private function kpiQuery(User $user, ?KpiPeriod $period, ?int $positionId = null): Builder
    {
        $query = $this->scopedKpis($user, $positionId);

        if (! $period) {
            return $query->whereIn('period_id', []);
        }

        return $query->where('period_id', $period->getKey());
    }

    private function scopedKpis(User $user, ?int $positionId = null): Builder
    {
        $query = EmployeeKpi::query();

        if ($positionId) {
            $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('position_id', $positionId));
        }

        if ($user->hasAnyRole(['owner_manager', 'super_admin', 'kpi_admin'])) {
            return $query;
        }

        $employeeId = $user->employee?->getKey();

        if (! $employeeId) {
            return $query->whereIn('employee_id', []);
        }

        if ($user->hasRole('supervisor')) {
            return $query->where('supervisor_id_snapshot', $employeeId);
        }

        return $query->where('employee_id', $employeeId);
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

        $averages = $this->scopedKpis($user, $positionId)
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

        $ranked = $this->kpiQuery($user, $period, $positionId)
            ->whereNotNull('final_score')
            ->select('employee_id')
            ->selectRaw('AVG(final_score) AS average_score')
            ->groupBy('employee_id')
            ->orderByDesc('average_score')
            ->limit(5)
            ->get();

        $employees = Employee::query()
            ->with('position')
            ->whereIn('id', $ranked->pluck('employee_id'))
            ->get()
            ->keyBy('id');

        return $ranked->map(function (EmployeeKpi $row) use ($employees): array {
            $employee = $employees->get($row->employee_id);

            return [
                'employee_id' => $row->employee_id,
                'name' => $employee?->name ?? 'Karyawan tidak ditemukan',
                'position' => $employee?->position?->name,
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
            ->with(['employee.position'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeKpi $kpi) => $this->kpiPayload($kpi))
            ->all();
    }

    private function attentionKpisPayload(User $user, ?KpiPeriod $period, ?int $positionId = null): array
    {
        if (! $period) {
            return [];
        }

        return $this->kpiQuery($user, $period, $positionId)
            ->with(['employee.position'])
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', ['revision_required', 'pending_approval'])
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereNotNull('final_score')
                            ->where('final_score', '<', 80);
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeKpi $kpi) => $this->kpiPayload($kpi))
            ->all();
    }

    private function kpiPayload(EmployeeKpi $kpi): array
    {
        return [
            'id' => $kpi->getKey(),
            'name' => $kpi->employee?->name ?? 'Karyawan tidak ditemukan',
            'position' => $kpi->employee?->position?->name,
            'status' => $kpi->status,
            'score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
            'progress' => $kpi->progress_percentage !== null
                ? (float) $kpi->progress_percentage
                : null,
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
            ->map(fn (SystemNotification $notification) => [
                'id' => $notification->getKey(),
                'title' => $notification->title,
                'body' => $notification->body,
                'type' => $notification->type,
                'action_url' => $notification->action_url,
                'is_read' => (bool) $notification->is_read,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])
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
                ->map(fn (KpiPeriod $item): array => [
                    'value' => (string) $item->getKey(),
                    'label' => $item->name,
                ])
                ->all(),
            'positions' => Position::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Position $item): array => [
                    'value' => (string) $item->getKey(),
                    'label' => $item->name,
                ])
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
