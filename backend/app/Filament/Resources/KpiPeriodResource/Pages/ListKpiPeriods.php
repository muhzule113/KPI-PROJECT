<?php

namespace App\Filament\Resources\KpiPeriodResource\Pages;

use App\Filament\Resources\KpiPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKpiPeriods extends ListRecords
{
    protected static string $resource = KpiPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Periode Baru'),
        ];
    }
}
