<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\TeamTaskSummaryService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamTaskController extends Controller
{
    public function __construct(protected TeamTaskSummaryService $teamTasks) {}

    public function __invoke(Request $request): Response
    {
        abort_unless(
            CapabilityMatrix::has($request->user(), 'kpi.supervisor.review')
                || CapabilityMatrix::has($request->user(), 'kpi.manager.approval'),
            403,
        );
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = array_key_exists('date', $request->query())
            ? ($data['date'] ?? null)
            : today()->toDateString();

        try {
            return Inertia::render('Admin/TeamTasks', $this->teamTasks->summary($request->user(), $date));
        } catch (\Throwable $exception) {
            return Inertia::render('Admin/TeamTasks', [
                'role' => null,
                'title' => 'Penilaian Tim',
                'date' => $date,
                'required_count' => 0,
                'optional_count' => 0,
                'employees' => [],
                'completed_employees' => [],
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
