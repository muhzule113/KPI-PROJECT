<?php

namespace App\Filament\Resources\AdminWorkLogResource\Pages;

use App\Filament\Resources\AdminWorkLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdminWorkLog extends EditRecord
{
    protected static string $resource = AdminWorkLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
