<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\EmployeeKpiItem;
use App\Models\KpiDailyEntry;
use App\Modules\Assessment\DailyAssessmentService;
use App\Support\CapabilityMatrix;
use App\Support\KpiVisibility;
use Illuminate\Auth\Access\AuthorizationException;
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
        abort_unless($request->user()->employee || $request->user()->hasRole('super_admin'), 403);
        $date = $this->date($request);

        try {
            $day = $this->dailyAssessmentService->employeeDay($request->user(), $date);
            $showScores = KpiVisibility::published($day['period']);

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
                    'scores_published' => $showScores,
                    'final_score' => $showScores && $day['kpi']->final_score !== null ? (float) $day['kpi']->final_score : null,
                ],
                'items' => $day['kpi']->items->map(function (EmployeeKpiItem $item) use ($day, $showScores): array {
                    $entry = $day['entries']->firstWhere('employee_kpi_item_id', $item->id);
                    $showActual = $showScores || (! $item->isManualRated() && $item->formula_key_snapshot !== 'rubric'
                        && ! in_array($item->definition_code_snapshot, ['SUP-01', 'SUP-02'], true));

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
                        'editable' => false,
                        'employee_actual' => $showActual && $entry?->employee_actual_decimal !== null
                            ? (float) $entry->employee_actual_decimal
                            : null,
                        'employee_actual_json' => $showActual ? $entry?->employee_actual_json : null,
                        'employee_note' => $entry?->employee_note,
                        'actual' => $showActual ? $entry?->effectiveActualDecimal() : null,
                        'entry_status' => $entry?->entry_status ?? 'draft',
                        'supervisor_status' => $entry?->supervisor_status ?? 'pending',
                        'manager_status' => $entry?->manager_status ?? 'pending',
                        'effective_actual' => $showActual ? $entry?->effectiveActualDecimal() : null,
                        'effective_rubric_score' => $showScores ? $entry?->effectiveRubricScore() : null,
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
        abort_unless($request->user()->employee || $request->user()->hasRole('super_admin'), 403);

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
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function supervisorQueue(Request $request): Response
    {
        abort_unless(CapabilityMatrix::has($request->user(), 'kpi.supervisor.daily'), 403);
        $date = $this->date($request);
        $kpiId = $request->validate(['kpi_id' => ['nullable', 'string']])['kpi_id'] ?? null;

        try {
            $entries = $this->dailyAssessmentService->supervisorQueue($request->user(), $date, $kpiId);
            $deadline = $this->dailyAssessmentService->assessmentDeadline($date, 'supervisor');
            $message = null;
            $canAssess = $deadline === null || ! $deadline->isPast();
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            $entries = collect();
            $deadline = null;
            $message = $exception->getMessage();
            $canAssess = false;
        }

        return Inertia::render('Admin/DailyAssessmentQueue', [
            'role' => 'supervisor',
            'title' => $kpiId ? 'Penilaian Tim' : 'Penilaian Harian Tim',
            'description' => $kpiId ? 'Selesaikan kehadiran dan penilaian karyawan ini dari atas ke bawah.' : 'Pilih tanggal untuk membuka antrean penilaian harian lama.',
            'date' => $date,
            'kpi_id' => $kpiId,
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

            $query = ['date' => $result->entry_date->toDateString()];
            if ($request->filled('kpi_id')) {
                $query['kpi_id'] = $request->string('kpi_id')->toString();
            }

            return redirect('/app/supervisor-daily-assessments?'.http_build_query($query))
                ->with('success', 'Penilaian harian Supervisor berhasil disimpan.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function approveAllSupervisor(Request $request, string $kpi): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        try {
            $result = $this->dailyAssessmentService->approveAllSupervisor($request->user(), $kpi, $data['date']);

            return redirect('/app/supervisor-daily-assessments?'.http_build_query(['date' => $result['date'], 'kpi_id' => $kpi]))
                ->with('success', "{$result['approved_count']} indikator otomatis berhasil dikonfirmasi.");
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function managerQueue(Request $request): Response
    {
        abort_unless(CapabilityMatrix::has($request->user(), 'kpi.manager.daily'), 403);
        $date = $this->date($request);
        $kpiId = $request->validate(['kpi_id' => ['nullable', 'string']])['kpi_id'] ?? null;

        try {
            $entries = $this->dailyAssessmentService->managerQueue($request->user(), $date, $kpiId);
            $deadline = $this->dailyAssessmentService->assessmentDeadline($date, 'manager');
            $message = null;
            $canAssess = $deadline === null || ! $deadline->isPast();
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            $entries = collect();
            $deadline = null;
            $message = $exception->getMessage();
            $canAssess = false;
        }

        return Inertia::render('Admin/DailyAssessmentQueue', [
            'role' => 'manager',
            'title' => $kpiId ? 'Penilaian Tim' : 'Tinjauan Opsional',
            'description' => $kpiId ? 'Selesaikan penilaian karyawan ini dari atas ke bawah.' : 'Tinjauan harian staf tidak memengaruhi daftar pekerjaan wajib.',
            'date' => $date,
            'kpi_id' => $kpiId,
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

            $query = ['date' => $result->entry_date->toDateString()];
            if ($request->filled('kpi_id')) {
                $query['kpi_id'] = $request->string('kpi_id')->toString();
            }

            return redirect('/app/manager-daily-assessments?'.http_build_query($query))
                ->with('success', 'Penilaian harian Manager berhasil disimpan dan total bulanan diperbarui.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function approveAllManager(Request $request, string $kpi): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        try {
            $result = $this->dailyAssessmentService->approveAllManager($request->user(), $kpi, $data['date']);

            return redirect("/app/manager-daily-assessments?date={$result['date']}")
                ->with('success', "{$result['approved_count']} indikator staf berhasil disetujui.");
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
            'kpi_id' => ['nullable', 'string'],
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
            'review_mode' => $kpi->isSupervisorKpi() ? 'supervisor_assessment' : 'staff_confirmation',
            'employee' => [
                'id' => (string) $employee?->id,
                'name' => $employee?->name,
                'position' => $kpi->positionSnapshot?->name,
                'branch' => $kpi->branchSnapshot?->name,
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
                'input_type' => $item->isAttendanceIndicator() ? 'attendance' : ($item->isManualRated() ? 'rating' : ($item->formula_key_snapshot === 'rubric' ? 'rubric' : 'numeric')),
                'manual_rating_options' => $item->isManualRated() ? $item->manualRatingOptions() : [],
                'attendance_options' => $item->isAttendanceIndicator() ? collect(Attendance::STATUSES)->map(fn (string $status): array => [
                    'value' => $status,
                    'label' => Attendance::statusLabel($status),
                ])->values()->all() : [],
                'system_actual' => $item->systemActualDecimal(),
                'system_meta' => $entry->system_actual_json ?? ($item->isSystemSourced() ? $item->actual_json : null),
            ],
            'entry_status' => $entry->entry_status,
            'system_actual' => $entry->system_actual_decimal !== null ? (float) $entry->system_actual_decimal : null,
            'employee_actual' => $entry->employee_actual_decimal !== null ? (float) $entry->employee_actual_decimal : null,
            'employee_note' => $entry->employee_note,
            'supervisor_actual' => $entry->supervisor_actual_decimal !== null ? (float) $entry->supervisor_actual_decimal : null,
            'supervisor_score' => $entry->supervisor_score_percentage !== null ? (float) $entry->supervisor_score_percentage : null,
            'supervisor_actual_json' => $entry->supervisor_actual_json,
            'supervisor_answers' => $entry->supervisor_answers_json,
            'supervisor_note' => $entry->supervisor_note,
            'supervisor_status' => $entry->supervisor_status,
            'manager_actual' => $entry->manager_actual_decimal !== null ? (float) $entry->manager_actual_decimal : null,
            'manager_score' => $entry->manager_score_percentage !== null ? (float) $entry->manager_score_percentage : null,
            'manager_actual_json' => $entry->manager_actual_json,
            'manager_answers' => $entry->manager_answers_json,
            'manager_note' => $entry->manager_note,
            'manager_status' => $entry->manager_status,
            'effective_actual' => $entry->effectiveActualDecimal(),
            'effective_score' => $entry->effectiveRubricScore(),
        ];
    }
}
