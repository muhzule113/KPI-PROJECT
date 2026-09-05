<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiPeriod;
use App\Modules\Import\CashierImportService;
use App\Support\MenuAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ImportController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize($request);

        $activePeriod = KpiPeriod::active();

        return Inertia::render('Admin/ImportUpload', [
            'periods' => ($activePeriod ? collect([$activePeriod]) : collect())
                ->map(fn (KpiPeriod $period): array => [
                    'value' => (string) $period->getKey(),
                    'label' => "{$period->name} ({$period->status})",
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:kpi_periods,id'],
            'report_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ]);
        $activePeriod = KpiPeriod::active();
        abort_unless($activePeriod && (int) $data['period_id'] === (int) $activePeriod->getKey(), 422, 'Import hanya dapat dilakukan pada periode KPI yang sedang OPEN.');

        try {
            $batch = app(CashierImportService::class)->uploadAndStage(
                $request->file('report_file'),
                KpiPeriod::findOrFail($data['period_id']),
                queue: true,
            );

            return redirect('/app/import-batches')->with('success', "File masuk antrean analisis. Batch {$batch->id} akan berubah menjadi siap preview setelah worker selesai.");
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    private function authorize(Request $request): void
    {
        abort_unless(MenuAccess::can($request->user(), [], ['POS-KSR']), 403);
    }
}
