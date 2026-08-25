<?php

namespace App\Filament\Resources\AuditEventResource\Pages;

use App\Filament\Resources\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;
}
