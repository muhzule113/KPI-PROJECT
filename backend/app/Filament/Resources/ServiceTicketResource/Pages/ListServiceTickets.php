<?php

namespace App\Filament\Resources\ServiceTicketResource\Pages;

use App\Filament\Resources\ServiceTicketResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceTickets extends ListRecords
{
    protected static string $resource = ServiceTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Tiket Servis Baru'),
        ];
    }
}
