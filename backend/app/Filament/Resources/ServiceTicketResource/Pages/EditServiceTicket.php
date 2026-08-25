<?php

namespace App\Filament\Resources\ServiceTicketResource\Pages;

use App\Filament\Resources\ServiceTicketResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceTicket extends EditRecord
{
    protected static string $resource = ServiceTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
