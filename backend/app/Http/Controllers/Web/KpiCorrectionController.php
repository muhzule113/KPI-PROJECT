<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Modules\Approval\ApprovalService;
use App\Support\KpiWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KpiCorrectionController extends Controller
{
    public function create(Request $request, string $record): Response
    {
        $kpi = $this->record($request, $record);

        return Inertia::render('Admin/KpiCorrectionForm', [
            'kpi' => [
                'id' => (string) $kpi->getKey(),
                'employee' => $kpi->employee?->name,
                'period' => $kpi->period?->name,
                'items' => $kpi->items->map(fn ($item): array => [
                    'id' => (string) $item->getKey(),
                    'code' => $item->definition_code_snapshot,
                    'name' => $item->name_snapshot,
                    'actual' => $item->actual_decimal,
                ])->values()->all(),
            ],
        ]);
    }

    public function store(Request $request, string $record): RedirectResponse
    {
        $kpi = $this->record($request, $record);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string'],
            'items.*.actual' => ['required', 'numeric'],
        ]);

        try {
            app(ApprovalService::class)->requestCorrection($kpi, $data['reason'], ['items' => $data['items']], $request->user()->getKey());

            return redirect('/app/employee-kpis')->with('success', 'Permintaan koreksi KPI berhasil diajukan.');
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    private function record(Request $request, string $record): EmployeeKpi
    {
        $kpi = EmployeeKpi::query()->with(['employee', 'period', 'items'])->findOrFail($record);
        abort_unless(KpiWorkflow::canRequestCorrection($request->user(), $kpi), 403);
        abort_unless(in_array($kpi->status, ['approved', 'locked'], true), 409);

        return $kpi;
    }
}
