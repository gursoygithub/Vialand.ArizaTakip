<?php

namespace App\Notifications;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic status change notification fired for every transition EXCEPT
 * OPEN → ASSIGNED (which keeps using TicketAssignedNotification).
 * Recipients: creator + current assignee minus the actor.
 */
class TicketStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?TaskStatusEnum $fromStatus,
        public readonly TaskStatusEnum $toStatus,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        // Status changes are database-only — the dedicated assigned/closed/
        // reopened/cancelled notifications carry the email payload for the
        // events that actually warrant inbox attention.
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' • Durum Değişti')
            ->body($this->renderBody())
            ->icon('heroicon-o-arrow-path')
            ->iconColor($this->iconColor())
            ->actions([
                Action::make('view')
                    ->label('Talebi Aç')
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function renderBody(): string
    {
        $from = $this->fromStatus?->getLabel() ?? '—';
        $to   = $this->toStatus->getLabel();

        return "{$from} → {$to} — {$this->actor->name} tarafından";
    }

    /**
     * Bell icon color tracks the destination status's intent.
     */
    private function iconColor(): string
    {
        return match ($this->toStatus) {
            TaskStatusEnum::CANCELLED                            => 'danger',
            TaskStatusEnum::ON_HOLD                              => 'warning',
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED                            => 'success',
            TaskStatusEnum::IN_PROGRESS,
            TaskStatusEnum::ASSIGNED                             => 'primary',
            default                                              => 'gray',
        };
    }
}
