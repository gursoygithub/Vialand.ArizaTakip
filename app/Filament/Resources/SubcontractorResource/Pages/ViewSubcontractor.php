<?php

namespace App\Filament\Resources\SubcontractorResource\Pages;

use App\Filament\Resources\SubcontractorResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubcontractor extends ViewRecord
{
    protected static string $resource = SubcontractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
