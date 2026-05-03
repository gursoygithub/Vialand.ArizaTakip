<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Hard gate: only the ticket creator OR users with view.all / view.group
     * can reach the edit page. The TicketPolicy::update check is permissive
     * (assigned employee can update too), so we apply this stricter rule
     * explicitly at mount-time per the wizard-flow spec.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $user = auth()->user();
        $ticket = $this->getRecord();

        // Edit is creator-or-admin only. ticket.view.group is a READ scope —
        // group supervisors can see their region's tickets but not rewrite
        // someone else's ticket; for that the user has to be the creator
        // (their own ticket) or a true admin (ticket.view.all).
        if (
            $ticket->created_by !== $user?->id
            && !$user?->hasPermissionTo('ticket.view.all')
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
