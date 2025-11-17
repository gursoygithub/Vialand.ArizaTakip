<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ViewRecord;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();
                    return $data;
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
