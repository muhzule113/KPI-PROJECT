<?php

namespace App\Filament\Resources\KpiCorrectionRequestResource\Pages;

use App\Filament\Resources\KpiCorrectionRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKpiCorrectionRequests extends ListRecords
{
    protected static string $resource = KpiCorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
