<?php

namespace App\Filament\Resources\SubcontractorResource\Pages;

use App\Filament\Resources\SubcontractorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubcontractor extends EditRecord
{
    protected static string $resource = SubcontractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    //redirect to view page after edit
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }
}
