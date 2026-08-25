<?php

namespace App\Filament\Resources\KpiDefinitionResource\Pages;

use App\Filament\Resources\KpiDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKpiDefinition extends EditRecord
{
    protected static string $resource = KpiDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
