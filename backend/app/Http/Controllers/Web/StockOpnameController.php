<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiPeriod;
use App\Models\StockOpname;
use App\Modules\Assessment\InventoryKpiSyncService;
use App\Modules\Assessment\StockOpnameService;
use App\Support\MenuAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StockOpnameController extends Controller
{
    public function edit(Request $request, string $record): Response
    {
        $opname = $this->record($request, $record);

        return Inertia::render('Admin/StockOpnameForm', [
            'opname' => [
                'id' => (string) $opname->getKey(),
                'code' => $opname->code,
                'period_id' => (string) $opname->period_id,
                'period' => $opname->period?->name,
                'deadline' => $opname->deadline?->toDateString(),
                'status' => $opname->status,
                'items' => $opname->items->map(fn ($item): array => [
                    'id' => (string) $item->getKey(),
                    'sparepart' => $item->sparepart?->name,
                    'code' => $item->sparepart?->code,
                    'system_stock' => (int) $item->system_stock,
                    'physical_stock' => $item->physical_stock,
                ])->values()->all(),
            ],
            'periods' => KpiPeriod::query()->orderByDesc('year')->orderByDesc('month')->get(['id', 'name'])->map(fn (KpiPeriod $period): array => ['value' => (string) $period->getKey(), 'label' => $period->name])->all(),
        ]);
    }

    public function update(Request $request, string $record): RedirectResponse
    {
        $opname = $this->record($request, $record);
        abort_if($opname->status === StockOpname::STATUS_COMPLETED, 409, 'Stock opname yang sudah selesai tidak dapat diubah.');

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'period_id' => ['required', 'integer', 'exists:kpi_periods,id'],
            'deadline' => ['nullable', 'date'],
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'string', 'exists:stock_opname_items,id'],
            'items.*.physical_stock' => ['required', 'integer', 'min:0'],
        ]);

        $opname->update([
            'code' => $data['code'] ?: $opname->code,
            'period_id' => $data['period_id'],
            'deadline' => $data['deadline'] ?? null,
        ]);
        foreach ($data['items'] as $item) {
            abort_unless($opname->items()->whereKey($item['id'])->exists(), 422, 'Item opname tidak valid.');
            $opname->items()->whereKey($item['id'])->update(['physical_stock' => $item['physical_stock']]);
        }

        return redirect('/app/stock-opnames')->with('success', 'Data stock opname berhasil disimpan.');
    }

    public function complete(Request $request, string $record): RedirectResponse
    {
        $opname = $this->record($request, $record);

        try {
            $result = app(StockOpnameService::class)->complete($opname, $request->user()->getKey());
            $period = $opname->fresh('period')?->period;
            if ($period && $opname->fresh()->status === StockOpname::STATUS_COMPLETED) {
                $result['message'] .= ' ' . app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period)['message'];
            }

            return redirect('/app/stock-opnames')->with('success', $result['message']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function record(Request $request, string $record): StockOpname
    {
        abort_unless(MenuAccess::can($request->user(), [], ['POS-GUD']), 403);

        return StockOpname::query()->with(['period', 'items.sparepart'])->findOrFail($record);
    }
}
