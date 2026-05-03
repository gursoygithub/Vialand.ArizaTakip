<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Hard gate: only the ticket creator OR super_admin can reach the edit
     * page. ticket.view.* are READ scopes — they don't grant write access.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $user = auth()->user();
        $ticket = $this->getRecord();

        if (
            $ticket->created_by !== $user?->id
            && !$user?->hasRole('super_admin')
        ) {
            abort(403, 'Bu talebi düzenleme yetkiniz bulunmuyor.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
