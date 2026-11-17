<?php

namespace App\Filament\Resources\WorkCalendarResource\Pages;

use App\Filament\Resources\WorkCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkCalendars extends ManageRecords
{
    protected static string $resource = WorkCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => WorkCalendarResource::normalize($data)),
        ];
    }
}
