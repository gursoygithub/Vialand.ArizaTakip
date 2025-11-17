<?php

namespace App\Filament\Resources\AreaResource\Pages;

use App\Filament\Resources\AreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewArea extends ViewRecord
{
    protected static string $resource = AreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->user()->id;
                    return $data;
                }),
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->subAreas()->count() === 0)
                ->action(function ($record) {
                    $record->deleted_by = auth()->user()->id;
                    $record->deleted_at = now();
                    $record->delete();

                    // redirect to area list after deletion
                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
