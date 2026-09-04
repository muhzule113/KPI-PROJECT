<?php

namespace App\Support;

use App\Models\ServiceTicket;

final class ServiceTicketNumber
{
    public static function next(): string
    {
        $prefix = 'SRV-' . now()->format('Ym') . '-';
        $last = ServiceTicket::where('ticket_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('ticket_number')
            ->value('ticket_number');
        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
