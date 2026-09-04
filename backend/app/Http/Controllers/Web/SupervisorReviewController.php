<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Modules\Review\ReviewService;
use App\Support\KpiWorkflow;
use App\Support\MenuAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupervisorReviewController extends Controller
{
    public function show(Request $request, string $record): Response
    {
        $kpi = $this->record($request, $record);

        return Inertia::render('Admin/SupervisorReview', [
            'kpi' => [
                'id' => (string) $kpi->getKey(),
                'employee' => [
                    'name' => $kpi->employee?->name,
                    'position' => $kpi->employee?->position?->name,
                    'branch' => $kpi->employee?->branch?->name,
                ],
                'period' => $kpi->period?->name,
                'status' => $kpi->status,
                'progress' => (float) $kpi->progress_percentage,
                'final_score' => $kpi->final_score !== null ? (float) $kpi->final_score : null,
                'rating_label' => $kpi->rating_label,
                'items' => $kpi->items->map(fn (EmployeeKpiItem $item): array => [
                    'id' => (string) $item->getKey(),
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'weight' => (float) $item->weight_snapshot,
                    'target' => $item->target_value_snapshot,
                    'unit' => $item->target_unit_snapshot,
                    'actual' => $item->actual_decimal !== null ? (float) $item->actual_decimal : null,
                    'achievement' => $item->achievement_percentage !== null ? (float) $item->achievement_percentage : null,
                    'status' => $item->status,
                    'formula' => $item->formula_key_snapshot,
                    'rubric' => $item->rubric_snapshot,
                ])->values()->all(),
            ],
        ]);
    }

    public function verifyItem(Request $request, string $record, string $item): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:valid,revision_required'],
            'reason' => ['nullable', 'string'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $item = EmployeeKpiItem::query()->where('employee_kpi_id', $kpi->getKey())->findOrFail($item);
            app(ReviewService::class)->verifyItem($item, $data['decision'], null, $data['reason'] ?? null, $request->user()->getKey());

            return back()->with('success', "{$item->definition_code_snapshot} berhasil diperbarui.");
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function submitRubric(Request $request, string $record, string $item): RedirectResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.criterion_id' => ['required', 'integer'],
            'answers.*.is_fulfilled' => ['required', 'boolean'],
            'answers.*.notes' => ['nullable', 'string'],
        ]);

        try {
            $kpi = $this->record($request, $record);
            $kpiItem = EmployeeKpiItem::query()->where('employee_kpi_id', $kpi->getKey())->findOrFail($item);
            app(ReviewService::class)->submitRubricAssessment($kpiItem, $data['answers'], $request->user()->getKey());

            return back()->with('success', "{$kpiItem->definition_code_snapshot} berhasil dinilai.");
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function requestRevision(Request $request, string $record): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5']]);

        try {
            $kpi = $this->record($request, $record);
            app(ReviewService::class)->requestRevision($kpi, $data['reason'], $request->user()->getKey());

            return redirect('/app/supervisor-reviews')->with('success', 'Permintaan revisi terkirim ke karyawan.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function forward(Request $request, string $record): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string']]);

        try {
            $kpi = $this->record($request, $record);
            app(ReviewService::class)->forwardToManager($kpi, $data['notes'] ?? null, $request->user()->getKey());

            return redirect('/app/supervisor-reviews')->with('success', 'KPI diteruskan ke antrean approval manager.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function record(Request $request, string $record): EmployeeKpi
    {
        $kpi = EmployeeKpi::query()
            ->with(['employee.position', 'employee.branch', 'period', 'items'])
            ->findOrFail($record);

        abort_unless(MenuAccess::can($request->user(), ['supervisor', 'super_admin'], []), 403);
        abort_unless(KpiWorkflow::canReviewKpi($request->user(), $kpi), 403);
        abort_unless(in_array($kpi->status, ['submitted', 'under_review', 'revision_required'], true), 409);

        return $kpi;
    }
}
