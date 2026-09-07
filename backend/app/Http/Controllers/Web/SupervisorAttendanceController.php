<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Assessment\SupervisorAttendanceService;
use App\Support\CapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SupervisorAttendanceController extends Controller
{
    public function __construct(
        protected SupervisorAttendanceService $attendanceService,
        protected DailyAssessmentService $dailyAssessmentService,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(CapabilityMatrix::has($request->user(), 'attendance.team.manage'), 403);
        $date = $this->date($request);

        try {
            $context = $this->attendanceService->roster($request->user(), $date);
            $this->dailyAssessmentService->supervisorQueue($request->user(), $date);
            $message = null;
        } catch (\Throwable $exception) {
            $context = ['period' => null, 'rows' => collect()];
            $message = $exception->getMessage();
        }

        return Inertia::render('Admin/SupervisorAttendance', [
            'date' => $date,
            'period' => $context['period'] ? [
                'id' => $context['period']->id,
                'name' => $context['period']->name,
                'start_date' => $context['period']->start_date?->toDateString(),
                'end_date' => $context['period']->end_date?->toDateString(),
            ] : null,
            'rows' => collect($context['rows'])->values()->all(),
            'statusOptions' => collect(Attendance::STATUSES)->map(fn (string $status): array => [
                'value' => $status,
                'label' => Attendance::statusLabel($status),
            ])->values()->all(),
            'message' => $message,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'statuses' => ['required', 'array'],
            'statuses.*' => ['nullable', 'string', Rule::in(Attendance::STATUSES)],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->attendanceService->record(
                supervisor: $request->user(),
                date: $data['date'],
                statuses: $data['statuses'],
                notes: $data['notes'] ?? [],
            );
            $this->dailyAssessmentService->supervisorQueue($request->user(), $data['date']);

            return redirect("/app/supervisor-attendance?date={$data['date']}")
                ->with('success', 'Absensi tim tersimpan dan KPI harian disinkronkan ke antrean Supervisor.');
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    private function date(Request $request): string
    {
        $date = $request->query('date', now()->toDateString());

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? $date
            : now()->toDateString();
    }
}
