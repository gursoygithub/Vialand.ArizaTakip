<?php

namespace App\Filament\Resources\SubcontractorEmployeeResource\Pages;

use App\Filament\Resources\SubcontractorEmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubcontractorEmployee extends ViewRecord
{
    protected static string $resource = SubcontractorEmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
        ];
    }
}
