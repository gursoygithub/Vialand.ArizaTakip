<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ui.create_new'))
                ->icon('heroicon-o-plus')
                ->url($this->getResource()::getUrl('create'))
                ->color('success')
                ->visible(fn () => auth()->user()->can('create_custom_group')),
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil-square'),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash'),
        ];
    }
}
