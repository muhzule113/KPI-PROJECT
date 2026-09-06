<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Reporting\DashboardDataService;
use App\Support\AdminNavigation;
use App\Support\CapabilityMatrix;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __construct(
        protected DashboardDataService $dashboardDataService,
    ) {}

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
        $dashboard = $this->dashboardDataService->payload($user, $periodId, $positionId);
        $canExport = CapabilityMatrix::has($user, 'reports.export');

        return [
            'activePeriod' => $dashboard['active_period'],
            'navigation' => AdminNavigation::for($user),
            'canExport' => $canExport,
            'filters' => $canExport ? $dashboard['filters'] : [],
            'metrics' => $dashboard['metrics'],
            'trend' => $dashboard['trend'],
            'topPerformers' => $dashboard['top_performers'],
            'recentKpis' => $dashboard['recent_kpis'],
            'attentionKpis' => $dashboard['attention_kpis'],
            'notifications' => $dashboard['notifications'],
            'myKpi' => $dashboard['my_kpi'],
            'supervisorQueue' => $dashboard['supervisor_queue'] ?? null,
            'executiveOverview' => $dashboard['executive_overview'] ?? null,
        ];
    }
}
