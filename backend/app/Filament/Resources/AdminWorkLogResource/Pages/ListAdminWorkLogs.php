<?php

namespace App\Filament\Resources\AdminWorkLogResource\Pages;

use App\Filament\Resources\AdminWorkLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdminWorkLogs extends ListRecords
{
    protected static string $resource = AdminWorkLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Catat Work-Log Harian'),
        ];
    }
}
