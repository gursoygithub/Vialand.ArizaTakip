<?php

namespace App\Filament\Resources\SlaPolicyResource\Pages;

use App\Filament\Resources\SlaPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSlaPolicy extends ViewRecord
{
    protected static string $resource = SlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ui.create_new'))
                ->icon('heroicon-o-plus')
                ->url($this->getResource()::getUrl('create'))
                ->color('success'),
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil-square'),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash')
                ->requiresConfirmation(),
        ];
    }
}
