<?php

namespace App\Filament\Resources\ComplaintResource\Pages;

use App\Filament\Resources\ComplaintResource;
use App\Models\Complaint;
use Filament\Resources\Pages\CreateRecord;

class CreateComplaint extends CreateRecord
{
    protected static string $resource = ComplaintResource::class;

    protected function afterCreate(): void
    {
        $complaint = $this->record;

        if (empty($complaint->code)) {
            $count = Complaint::whereYear('created_at', date('Y'))->count() + 1;
            $complaint->update(['code' => 'CMP-' . date('Ym') . '-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT)]);
        }

        if (empty($complaint->recorded_by)) {
            $complaint->update(['recorded_by' => auth()->id()]);
        }
    }
}
