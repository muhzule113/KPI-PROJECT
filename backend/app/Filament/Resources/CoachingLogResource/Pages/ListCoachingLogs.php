<?php

namespace App\Filament\Resources\CoachingLogResource\Pages;

use App\Filament\Resources\CoachingLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCoachingLogs extends ListRecords
{
    protected static string $resource = CoachingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Catat Sesi Coaching'),
        ];
    }
}
