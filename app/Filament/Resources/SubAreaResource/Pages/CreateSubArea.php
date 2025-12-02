<?php

namespace App\Filament\Resources\SubAreaResource\Pages;

use App\Filament\Resources\SubAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSubArea extends CreateRecord
{
    protected static string $resource = SubAreaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()->id;
        return $data;
    }
}
