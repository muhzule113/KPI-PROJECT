<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Modules\Approval\ApprovalService;
use App\Modules\Review\ReviewService;
use App\Support\KpiWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ManagerApprovalController extends Controller
{
    public function show(Request $request, string $record): Response
    {
        $kpi = $this->record($request, $record);

        return Inertia::render('Admin/ManagerAssessment', [
            'kpi' => [
                'id' => (string) $kpi->getKey(),
                'employee' => [
                    'name' => $kpi->employee?->name,
                    'position' => $kpi->employee?->position?->name,
                    'branch' => $kpi->employee?->branch?->name,
                ],
                'period' => $kpi->period?->name,
                'status' => $kpi->status,
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'rating_label' => $kpi->rating_label,
                'items' => $kpi->items->map(fn (EmployeeKpiItem $item): array => [
                    'id' => (string) $item->getKey(),
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'weight' => (float) $item->weight_snapshot,
                    'target' => $item->target_value_snapshot !== null ? (float) $item->target_value_snapshot : null,
                    'unit' => $item->target_unit_snapshot,
                    'formula' => $item->formula_key_snapshot,
                    'actual' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                    'achievement' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                    'weighted_score' => $item->weighted_score !== null ? (float) $item->weighted_score : null,
                    'status' => $item->status,
                    'rubric' => $item->rubric_snapshot,
                    'assessment' => $item->assessment ? [
                        'answers' => $item->assessment->answers->map(fn ($answer): array => [
                            'criterion_id' => $answer->criterion_id,
                            'is_fulfilled' => (bool) $answer->is_fulfilled,
                        ])->values()->all(),
                    ] : null,
                ])->values()->all(),
            ],
        ]);
    }

    public function assessItem(Request $request, string $record, string $item): RedirectResponse
    {
        $data = $request->validate([
            'actual_decimal' => ['required', 'numeric'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $kpiItem = EmployeeKpiItem::query()
                ->where('employee_kpi_id', $kpi->getKey())
                ->findOrFail($item);
            app(ApprovalService::class)->assessItem(
                item: $kpiItem,
                actualDecimal: (float) $data['actual_decimal'],
                note: $data['note'] ?? null,
                assessorId: $request->user()->getKey()
            );

            return back()->with('success', "{$kpiItem->definition_code_snapshot} berhasil dinilai Manager.");
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function assessRubric(Request $request, string $record, string $item): RedirectResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.criterion_id' => ['required', 'integer'],
            'answers.*.is_fulfilled' => ['required', 'boolean'],
            'answers.*.notes' => ['nullable', 'string'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $kpiItem = EmployeeKpiItem::query()
                ->where('employee_kpi_id', $kpi->getKey())
                ->findOrFail($item);
            app(ReviewService::class)->submitRubricAssessment(
                item: $kpiItem,
                answers: $data['answers'],
                reviewerId: $request->user()->getKey()
            );

            return back()->with('success', "{$kpiItem->definition_code_snapshot} berhasil dinilai Manager.");
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function approve(Request $request, string $record): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $result = app(ApprovalService::class)->approve(
                $kpi,
                $data['note'] ?? null,
                $request->user()->getKey()
            );

            return redirect()
                ->route('app.resource.index', ['resource' => 'employee-kpis'])
                ->with('success', $result['message']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function returnToSupervisor(Request $request, string $record): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $result = app(ApprovalService::class)->return(
                $kpi,
                $data['reason'],
                $request->user()->getKey()
            );

            return redirect()
                ->route('app.resource.index', ['resource' => 'employee-kpis'])
                ->with('success', $result['message']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function record(Request $request, string $record): EmployeeKpi
    {
        $kpi = EmployeeKpi::query()
            ->with(['employee.position', 'employee.branch', 'period', 'items.assessment.answers'])
            ->findOrFail($record);

        abort_unless(KpiWorkflow::canManageKpi($request->user(), $kpi), 403);
        abort_unless($kpi->status === 'pending_approval', 409);

        return $kpi;
    }
}
