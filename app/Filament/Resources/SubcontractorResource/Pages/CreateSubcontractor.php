<?php

namespace App\Filament\Resources\SubcontractorResource\Pages;

use App\Filament\Resources\SubcontractorResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSubcontractor extends CreateRecord
{
    protected static string $resource = SubcontractorResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
