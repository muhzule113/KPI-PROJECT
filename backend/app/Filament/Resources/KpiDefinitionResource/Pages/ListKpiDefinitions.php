<?php

namespace App\Filament\Resources\KpiDefinitionResource\Pages;

use App\Filament\Resources\KpiDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKpiDefinitions extends ListRecords
{
    protected static string $resource = KpiDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Definisi Baru'),
        ];
    }
}
