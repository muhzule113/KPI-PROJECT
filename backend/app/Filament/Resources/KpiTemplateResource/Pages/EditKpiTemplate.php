<?php

namespace App\Filament\Resources\KpiTemplateResource\Pages;

use App\Filament\Resources\KpiTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKpiTemplate extends EditRecord
{
    protected static string $resource = KpiTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
