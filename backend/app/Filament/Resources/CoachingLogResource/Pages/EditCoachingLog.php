<?php

namespace App\Filament\Resources\CoachingLogResource\Pages;

use App\Filament\Resources\CoachingLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCoachingLog extends EditRecord
{
    protected static string $resource = CoachingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
