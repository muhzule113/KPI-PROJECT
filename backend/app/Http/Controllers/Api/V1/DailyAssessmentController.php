<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiDailyEntry;
use App\Modules\Assessment\DailyAssessmentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyAssessmentController extends Controller
{
    public function __construct(
        protected DailyAssessmentService $dailyAssessmentService
    ) {}

    public function employeeDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $day = $this->dailyAssessmentService->employeeDay(
                $request->user(),
                $data['date'] ?? now()->toDateString()
            );

            return response()->json([
                'success' => true,
                'data' => $this->employeeDayPayload($day),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function saveEmployeeDay(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Karyawan tidak mengisi KPI harian. Nilai KPI ditentukan sistem, Supervisor, atau Manager.',
        ], 403);
    }

    public function supervisorQueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $date = $data['date'] ?? now()->toDateString();
            $entries = $this->dailyAssessmentService->supervisorQueue($request->user(), $date);

            return response()->json([
                'success' => true,
                'date' => $date,
                'data' => $entries->map(fn (KpiDailyEntry $entry) => $this->entryPayload($entry))->values(),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function assessSupervisor(Request $request, int $entryId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,revision_required'],
            'actual_decimal' => ['nullable', 'numeric'],
            'actual_json' => ['nullable', 'array'],
            'answers' => ['nullable', 'array'],
            'answers.*.criterion_id' => ['required', 'integer'],
            'answers.*.is_fulfilled' => ['required', 'boolean'],
            'answers.*.notes' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $entry = $this->dailyAssessmentService->assessSupervisor(
                user: $request->user(),
                entryId: $entryId,
                decision: $data['decision'],
                actualDecimal: isset($data['actual_decimal']) ? (float) $data['actual_decimal'] : null,
                actualJson: $data['actual_json'] ?? null,
                answers: $data['answers'] ?? null,
                note: $data['note'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Penilaian harian Supervisor berhasil disimpan.',
                'data' => $this->entryPayload($entry),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function managerQueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $date = $data['date'] ?? now()->toDateString();
            $entries = $this->dailyAssessmentService->managerQueue($request->user(), $date);

            return response()->json([
                'success' => true,
                'date' => $date,
                'data' => $entries->map(fn (KpiDailyEntry $entry) => $this->entryPayload($entry))->values(),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function assessManager(Request $request, int $entryId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,revision_required'],
            'actual_decimal' => ['nullable', 'numeric'],
            'actual_json' => ['nullable', 'array'],
            'answers' => ['nullable', 'array'],
            'answers.*.criterion_id' => ['required', 'integer'],
            'answers.*.is_fulfilled' => ['required', 'boolean'],
            'answers.*.notes' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $entry = $this->dailyAssessmentService->assessManager(
                user: $request->user(),
                entryId: $entryId,
                decision: $data['decision'],
                actualDecimal: isset($data['actual_decimal']) ? (float) $data['actual_decimal'] : null,
                actualJson: $data['actual_json'] ?? null,
                answers: $data['answers'] ?? null,
                note: $data['note'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Penilaian harian Manager berhasil disimpan dan total bulanan diperbarui.',
                'data' => $this->entryPayload($entry),
            ]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function employeeDayPayload(array $day): array
    {
        return [
            'date' => $day['date'],
            'period' => [
                'id' => $day['period']->id,
                'name' => $day['period']->name,
                'start_date' => $day['period']->start_date?->toDateString(),
                'end_date' => $day['period']->end_date?->toDateString(),
            ],
            'kpi' => [
                'id' => $day['kpi']->id,
                'status' => $day['kpi']->status,
                'final_score' => $day['kpi']->final_score !== null ? (float) $day['kpi']->final_score : null,
            ],
            'items' => collect($day['entries'])
                ->map(fn (KpiDailyEntry $entry) => $this->entryPayload($entry))
                ->values(),
        ];
    }

    private function entryPayload(KpiDailyEntry $entry): array
    {
        $item = $entry->item;
        $kpi = $item->employeeKpi;
        $employee = $kpi->employee;

        return [
            'id' => $entry->id,
            'date' => $entry->entry_date?->toDateString(),
            'kpi_id' => $kpi->id,
            'employee' => [
                'id' => $employee?->id,
                'name' => $employee?->name,
                'position' => $employee?->position?->name,
                'branch' => $employee?->branch?->name,
            ],
            'item' => [
                'id' => $item->id,
                'code' => $item->definition_code_snapshot,
                'name' => $item->name_snapshot,
                'weight' => (float) $item->weight_snapshot,
                'target_value' => $item->target_value_snapshot !== null ? (float) $item->target_value_snapshot : null,
                'target_unit' => $item->target_unit_snapshot,
                'formula' => $item->formula_key_snapshot,
                'source_type' => $item->source_type_snapshot,
                'rubric' => $item->rubric_snapshot,
                'system_actual' => $item->systemActualDecimal(),
                'system_meta' => $item->isSystemSourced() ? $item->actual_json : null,
            ],
            'entry_status' => $entry->entry_status,
            'supervisor_actual_decimal' => $entry->supervisor_actual_decimal !== null ? (float) $entry->supervisor_actual_decimal : null,
            'supervisor_score_percentage' => $entry->supervisor_score_percentage !== null ? (float) $entry->supervisor_score_percentage : null,
            'supervisor_answers' => $entry->supervisor_answers_json,
            'supervisor_note' => $entry->supervisor_note,
            'supervisor_status' => $entry->supervisor_status,
            'manager_actual_decimal' => $entry->manager_actual_decimal !== null ? (float) $entry->manager_actual_decimal : null,
            'manager_score_percentage' => $entry->manager_score_percentage !== null ? (float) $entry->manager_score_percentage : null,
            'manager_answers' => $entry->manager_answers_json,
            'manager_note' => $entry->manager_note,
            'manager_status' => $entry->manager_status,
            'effective_actual_decimal' => $entry->effectiveActualDecimal(),
            'effective_rubric_score' => $entry->effectiveRubricScore(),
        ];
    }
}
