<?php

namespace App\Filament\Resources\AdminWorkLogResource\Pages;

use App\Filament\Resources\AdminWorkLogResource;
use App\Models\KpiPeriod;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminWorkLog extends CreateRecord
{
    protected static string $resource = AdminWorkLogResource::class;

    protected function afterCreate(): void
    {
        $log = $this->record;

        // Isi periode default = periode OPEN saat ini
        if (empty($log->period_id)) {
            $period = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
            if ($period) {
                $log->update(['period_id' => $period->id]);
            }
        }

        if (empty($log->recorded_by)) {
            $log->update(['recorded_by' => auth()->id()]);
        }
    }
}
