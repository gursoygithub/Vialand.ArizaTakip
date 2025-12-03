<?php

namespace App\Filament\Resources\UnitResource\Pages;

use App\Filament\Resources\UnitResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUnit extends ViewRecord
{
    protected static string $resource = UnitResource::class;

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
//                ->action(function ($record) {
//                    $record->deleted_by = auth()->user()->id;
//                    $record->deleted_at = now();
//                    $record->save();
//
//                    $record->delete();
//
//                    // redirect to area list after deletion
//                    return redirect($this->getResource()::getUrl('index'));
//                }),
        ];
    }
}
