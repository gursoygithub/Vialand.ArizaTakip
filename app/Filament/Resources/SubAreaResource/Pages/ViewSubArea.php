<?php

namespace App\Filament\Resources\SubAreaResource\Pages;

use App\Filament\Resources\SubAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubArea extends ViewRecord
{
    protected static string $resource = SubAreaResource::class;

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
                ->action(function ($record) {
                    $record->deleted_by = auth()->user()->id;
                    $record->deleted_at = now();
                    $record->delete();

                    // redirect to sub-area list after deletion
                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
