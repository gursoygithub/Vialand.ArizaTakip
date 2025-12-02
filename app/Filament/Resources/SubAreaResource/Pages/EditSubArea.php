<?php

namespace App\Filament\Resources\SubAreaResource\Pages;

use App\Filament\Resources\SubAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubArea extends EditRecord
{
    protected static string $resource = SubAreaResource::class;

    // redirect to view page after edit
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->action(function ($record) {
                    $record->deleted_by = auth()->user()->id;
                    $record->deleted_at = now();
                    $record->delete();

                    // redirect to sub-area list page after delete
                    return redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->user()->id;
        return $data;
    }
}
