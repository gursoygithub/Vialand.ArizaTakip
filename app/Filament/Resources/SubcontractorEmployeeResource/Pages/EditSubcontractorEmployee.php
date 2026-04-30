<?php

namespace App\Filament\Resources\SubcontractorEmployeeResource\Pages;

use App\Filament\Resources\SubcontractorEmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubcontractorEmployee extends EditRecord
{
    protected static string $resource = SubcontractorEmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
