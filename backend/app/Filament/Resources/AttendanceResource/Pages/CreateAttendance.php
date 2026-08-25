<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function afterCreate(): void
    {
        if (empty($this->record->recorded_by)) {
            $this->record->update(['recorded_by' => auth()->id()]);
        }
    }
}
