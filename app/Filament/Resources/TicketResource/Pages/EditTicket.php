<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\TicketResource;
use App\Services\TicketService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    private ?TaskPriorityEnum $oldPriority = null;

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

    protected function beforeSave(): void
    {
        // Capture the priority from the DB-backed original before save
        // syncs it to the new value. afterSave compares against the live
        // record to decide whether to fan out the priority-change alert.
        $original = $this->getRecord()->getOriginal('priority');
        $this->oldPriority = $original instanceof TaskPriorityEnum
            ? $original
            : ($original !== null ? TaskPriorityEnum::tryFrom((int) $original) : null);
    }

    protected function afterSave(): void
    {
        $ticket = $this->getRecord();

        if (!$ticket->wasChanged('priority')) {
            return;
        }

        $newPriority = $ticket->priority;
        if (!$this->oldPriority || !$newPriority || $this->oldPriority === $newPriority) {
            return;
        }

        app(TicketService::class)->notifyPriorityChange(
            $ticket,
            $this->oldPriority,
            $newPriority,
            auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
