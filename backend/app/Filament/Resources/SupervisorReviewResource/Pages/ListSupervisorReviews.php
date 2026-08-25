<?php

namespace App\Filament\Resources\SupervisorReviewResource\Pages;

use App\Filament\Resources\SupervisorReviewResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupervisorReviews extends ListRecords
{
    protected static string $resource = SupervisorReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
