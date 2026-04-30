<?php

namespace App\Filament\Resources\SubcontractorResource\Pages;

use App\Filament\Resources\SubcontractorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubcontractors extends ListRecords
{
    protected static string $resource = SubcontractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
