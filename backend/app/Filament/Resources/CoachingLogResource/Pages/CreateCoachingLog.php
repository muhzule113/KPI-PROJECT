<?php

namespace App\Filament\Resources\CoachingLogResource\Pages;

use App\Filament\Resources\CoachingLogResource;
use App\Models\KpiPeriod;
use Filament\Resources\Pages\CreateRecord;

class CreateCoachingLog extends CreateRecord
{
    protected static string $resource = CoachingLogResource::class;

    protected function afterCreate(): void
    {
        $log = $this->record;

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
