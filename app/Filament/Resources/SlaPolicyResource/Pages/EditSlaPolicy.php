<?php

namespace App\Filament\Resources\SlaPolicyResource\Pages;

use App\Filament\Resources\SlaPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSlaPolicy extends EditRecord
{
    protected static string $resource = SlaPolicyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->icon('heroicon-o-eye'),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash')
                ->requiresConfirmation(),
        ];
    }
}
