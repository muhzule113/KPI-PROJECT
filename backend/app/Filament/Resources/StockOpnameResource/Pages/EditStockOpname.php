<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Filament\Resources\StockOpnameResource;
use App\Models\KpiPeriod;
use App\Models\StockOpname;
use App\Modules\Assessment\InventoryKpiSyncService;
use App\Modules\Assessment\StockOpnameService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStockOpname extends EditRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('complete')
                ->label('Selesaikan Opname & Hitung KPI')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Selesaikan Stock Opname?')
                ->modalDescription('Item yang sudah diisi stok fisiknya akan dihitung selisihnya, stok sistem disesuaikan, dan KPI gudang (GUD-01, GUD-02, GUD-05) diperbarui otomatis.')
                ->modalSubmitActionLabel('Ya, Selesaikan')
                ->action(function () {
                    $opname = $this->record;

                    $res = app(StockOpnameService::class)->complete($opname, auth()->id());
                    $msg = $res['message'];

                    $period = $opname->period ?? KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
                    if ($period && $opname->status === StockOpname::STATUS_COMPLETED) {
                        $syncRes = app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period);
                        $msg .= ' ' . $syncRes['message'];
                    }

                    Notification::make()->title($msg)->success()->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
