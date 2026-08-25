<?php

namespace App\Filament\Resources\KpiPeriodResource\Pages;

use App\Filament\Resources\KpiPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKpiPeriod extends EditRecord
{
    protected static string $resource = KpiPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
