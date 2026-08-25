<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Filament\Resources\StockOpnameResource;
use App\Models\StockOpname;
use App\Modules\Assessment\StockOpnameService;
use Filament\Resources\Pages\CreateRecord;

class CreateStockOpname extends CreateRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function afterCreate(): void
    {
        $opname = $this->record;

        // Auto-generate kode jika kosong
        if (empty($opname->code)) {
            $count = StockOpname::whereYear('created_at', date('Y'))->count() + 1;
            $opname->update(['code' => 'OPN-' . date('Ym') . '-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT)]);
        }

        // Snapshot stok sistem dari database untuk semua sparepart
        app(StockOpnameService::class)->snapshotItems($opname);
    }
}
