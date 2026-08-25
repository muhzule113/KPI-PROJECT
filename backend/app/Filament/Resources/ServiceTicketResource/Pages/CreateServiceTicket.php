<?php

namespace App\Filament\Resources\ServiceTicketResource\Pages;

use App\Filament\Resources\ServiceTicketResource;
use App\Models\KpiPeriod;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceTicket extends CreateRecord
{
    protected static string $resource = ServiceTicketResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if ($activePeriod) {
            $data['period_id'] = $activePeriod->id;
        }

        $ticketCount = \App\Models\ServiceTicket::whereYear('created_at', date('Y'))->whereMonth('created_at', date('m'))->count() + 1;
        $data['ticket_number'] = 'SRV-' . date('Ym') . '-' . str_pad((string)$ticketCount, 4, '0', STR_PAD_LEFT);

        return $data;
    }
}
