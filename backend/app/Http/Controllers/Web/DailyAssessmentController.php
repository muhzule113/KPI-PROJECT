<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpiItem;
use App\Models\KpiDailyEntry;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DailyAssessmentController extends Controller
{
    public function __construct(
        protected DailyAssessmentService $dailyAssessmentService
    ) {}

    public function employee(Request $request): Response
    {
        abort_unless($request->user()->employee, 403);
        $date = $this->date($request);

        try {
            $day = $this->dailyAssessmentService->employeeDay($request->user(), $date);

            return Inertia::render('Employee/DailyKpi', [
                'date' => $date,
                'period' => [
                    'name' => $day['period']->name,
                    'start_date' => $day['period']->start_date?->toDateString(),
                    'end_date' => $day['period']->end_date?->toDateString(),
                ],
                'kpi' => [
                    'id' => (string) $day['kpi']->id,
                    'status' => $day['kpi']->status,
                    'final_score' => $day['kpi']->final_score !== null ? (float) $day['kpi']->final_score : null,
                ],
                'items' => $day['kpi']->items->map(function (EmployeeKpiItem $item) use ($day): array {
                    $entry = $day['entries']->firstWhere('employee_kpi_item_id', $item->id);

                    return [
                        'id' => (string) $item->id,
                        'code' => $item->definition_code_snapshot,
                        'name' => $item->name_snapshot,
                        'weight' => (float) $item->weight_snapshot,
                        'target' => $item->target_value_snapshot !== null ? (float) $item->target_value_snapshot : null,
                        'unit' => $item->target_unit_snapshot,
                        'formula' => $item->formula_key_snapshot,
                        'source_type' => $item->source_type_snapshot,
                        'rubric' => $item->rubric_snapshot,
                        'editable' => strtolower((string) $item->source_type_snapshot) === 'employee'
                            && $item->formula_key_snapshot !== 'rubric'
                            && !in_array($entry?->supervisor_status, ['approved'], true)
                            && !in_array($entry?->manager_status, ['approved'], true)
                            && ($entry?->employee_submitted_at === null
                                || $entry?->supervisor_status === 'revision_required'
                                || $entry?->manager_status === 'revision_required'),
                        'employee_actual' => $entry?->employee_actual_decimal !== null
                            ? (float) $entry->employee_actual_decimal
                            : null,
                        'employee_actual_json' => $entry?->employee_actual_json,
                        'employee_note' => $entry?->employee_note,
                        'actual' => $entry?->effectiveActualDecimal(),
                        'entry_status' => $entry?->entry_status ?? 'draft',
                        'supervisor_status' => $entry?->supervisor_status ?? 'pending',
                        'manager_status' => $entry?->manager_status ?? 'pending',
                        'effective_actual' => $entry?->effectiveActualDecimal(),
                        'effective_rubric_score' => $entry?->effectiveRubricScore(),
                    ];
                })->values()->all(),
            ]);
        } catch (\Throwable $exception) {
            return Inertia::render('Employee/DailyKpi', [
                'date' => $date,
                'period' => null,
                'kpi' => null,
                'items' => [],
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function legacyKpi(Request $request, string $kpi): RedirectResponse
    {
        abort_unless($request->user()->employee, 403);

        return redirect('/app/my-kpi/daily');
    }

    public function saveEmployee(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'submit' => ['sometimes', 'boolean'],
            'items' => ['required', 'array'],
            'items.*.item_id' => ['nullable', 'string'],
            'items.*.id' => ['nullable', 'string'],
            'items.*.actual_decimal' => ['nullable', 'numeric'],
            'items.*.actual_json' => ['nullable', 'array'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->dailyAssessmentService->saveEmployeeDay(
                user: $request->user(),
                date: $data['date'],
                items: $data['items'],
                submit: (bool) ($data['submit'] ?? false)
            );

            return redirect("/app/my-kpi/daily?date={$data['date']}")
                ->with('success', ($data['submit'] ?? false)
                    ? 'KPI harian berhasil disubmit untuk review.'
                    : 'Draft KPI harian berhasil disimpan.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function supervisorQueue(Request $request): Response
    {
        abort_unless($request->user()->hasRole('supervisor') && !$request->user()->hasRole('super_admin'), 403);
        $date = $this->date($request);

        try {
            $entries = $this->dailyAssessmentService->supervisorQueue($request->user(), $date);
            $deadline = $this->dailyAssessmentService->assessmentDeadline($date, 'supervisor');
            $message = null;
            $canAssess = $deadline === null || ! $deadline->isPast();
        } catch (\Throwable $exception) {
            $entries = collect();
            $deadline = null;
            $message = $exception->getMessage();
            $canAssess = false;
        }

        return Inertia::render('Admin/DailyAssessmentQueue', [
            'role' => 'supervisor',
            'title' => 'Review KPI Harian',
            'description' => 'Validasi data harian karyawan sebelum diteruskan ke Manager.',
            'date' => $date,
            'entries' => $entries->map(fn (KpiDailyEntry $entry) => $this->entryPayload($entry))->values()->all(),
            'message' => $message,
            'deadline' => $deadline?->toIso8601String(),
            'canAssess' => $canAssess,
        ]);
    }

    public function assessSupervisor(Request $request, int $entry): RedirectResponse
    {
        $data = $this->assessmentData($request);

        try {
            $result = $this->dailyAssessmentService->assessSupervisor(
                user: $request->user(),
                entryId: $entry,
                decision: $data['decision'],
                actualDecimal: isset($data['actual_decimal']) ? (float) $data['actual_decimal'] : null,
                actualJson: $data['actual_json'] ?? null,
                answers: $data['answers'] ?? null,
                note: $data['note'] ?? null
            );

            return redirect("/app/supervisor-daily-assessments?date={$result->entry_date->toDateString()}")
                ->with('success', 'Penilaian harian Supervisor berhasil disimpan.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function managerQueue(Request $request): Response
    {
        abort_unless($request->user()->hasRole('owner_manager') && !$request->user()->hasRole('super_admin'), 403);
        $date = $this->date($request);

        try {
            $entries = $this->dailyAssessmentService->managerQueue($request->user(), $date);
            $deadline = $this->dailyAssessmentService->assessmentDeadline($date, 'manager');
            $message = null;
            $canAssess = $deadline === null || ! $deadline->isPast();
        } catch (\Throwable $exception) {
            $entries = collect();
            $deadline = null;
            $message = $exception->getMessage();
            $canAssess = false;
        }

        return Inertia::render('Admin/DailyAssessmentQueue', [
            'role' => 'manager',
            'title' => 'Penilaian Manager Harian',
            'description' => 'Konfirmasi nilai sistem atau nilai indikator yang sudah divalidasi Supervisor. Hasilnya menjadi total KPI bulanan.',
            'date' => $date,
            'entries' => $entries->map(fn (KpiDailyEntry $entry) => $this->entryPayload($entry))->values()->all(),
            'message' => $message,
            'deadline' => $deadline?->toIso8601String(),
            'canAssess' => $canAssess,
        ]);
    }

    public function assessManager(Request $request, int $entry): RedirectResponse
    {
        $data = $this->assessmentData($request);

        try {
            $result = $this->dailyAssessmentService->assessManager(
                user: $request->user(),
                entryId: $entry,
                decision: $data['decision'],
                actualDecimal: isset($data['actual_decimal']) ? (float) $data['actual_decimal'] : null,
                actualJson: $data['actual_json'] ?? null,
                answers: $data['answers'] ?? null,
                note: $data['note'] ?? null
            );

            return redirect("/app/manager-daily-assessments?date={$result->entry_date->toDateString()}")
                ->with('success', 'Penilaian harian Manager berhasil disimpan dan total bulanan diperbarui.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function assessmentData(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', 'in:approved,revision_required'],
            'actual_decimal' => ['nullable', 'numeric'],
            'actual_json' => ['nullable', 'array'],
            'answers' => ['nullable', 'array'],
            'answers.*.criterion_id' => ['required', 'integer'],
            'answers.*.is_fulfilled' => ['required', 'boolean'],
            'answers.*.notes' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function date(Request $request): string
    {
        $date = $request->query('date', now()->toDateString());
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : now()->toDateString();
    }

    private function entryPayload(KpiDailyEntry $entry): array
    {
        $item = $entry->item;
        $kpi = $item->employeeKpi;
        $employee = $kpi->employee;

        return [
            'id' => $entry->id,
            'date' => $entry->entry_date?->toDateString(),
            'kpi_id' => (string) $kpi->id,
            'employee' => [
                'id' => (string) $employee?->id,
                'name' => $employee?->name,
                'position' => $employee?->position?->name,
                'branch' => $employee?->branch?->name,
            ],
            'item' => [
                'code' => $item->definition_code_snapshot,
                'name' => $item->name_snapshot,
                'weight' => (float) $item->weight_snapshot,
                'target' => $item->target_value_snapshot !== null ? (float) $item->target_value_snapshot : null,
                'unit' => $item->target_unit_snapshot,
                'formula' => $item->formula_key_snapshot,
                'source_type' => $item->source_type_snapshot,
                'rubric' => $item->rubric_snapshot,
                'system_actual' => $item->systemActualDecimal(),
                'system_meta' => $entry->system_actual_json ?? ($item->isSystemSourced() ? $item->actual_json : null),
            ],
            'entry_status' => $entry->entry_status,
            'system_actual' => $entry->system_actual_decimal !== null ? (float) $entry->system_actual_decimal : null,
            'employee_actual' => $entry->employee_actual_decimal !== null ? (float) $entry->employee_actual_decimal : null,
            'employee_note' => $entry->employee_note,
            'supervisor_actual' => $entry->supervisor_actual_decimal !== null ? (float) $entry->supervisor_actual_decimal : null,
            'supervisor_score' => $entry->supervisor_score_percentage !== null ? (float) $entry->supervisor_score_percentage : null,
            'supervisor_answers' => $entry->supervisor_answers_json,
            'supervisor_note' => $entry->supervisor_note,
            'supervisor_status' => $entry->supervisor_status,
            'manager_actual' => $entry->manager_actual_decimal !== null ? (float) $entry->manager_actual_decimal : null,
            'manager_score' => $entry->manager_score_percentage !== null ? (float) $entry->manager_score_percentage : null,
            'manager_answers' => $entry->manager_answers_json,
            'manager_note' => $entry->manager_note,
            'manager_status' => $entry->manager_status,
            'effective_actual' => $entry->effectiveActualDecimal(),
            'effective_score' => $entry->effectiveRubricScore(),
        ];
    }
}
