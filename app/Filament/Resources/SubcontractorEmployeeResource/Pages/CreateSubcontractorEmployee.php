<?php

namespace App\Filament\Resources\SubcontractorEmployeeResource\Pages;

use App\Filament\Resources\SubcontractorEmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSubcontractorEmployee extends CreateRecord
{
    protected static string $resource = SubcontractorEmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
