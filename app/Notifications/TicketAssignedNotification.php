<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('notifications.mail_enabled', false)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ui.task_assigned') . ': ' . $this->ticket->ticket_no)
            ->line(__('ui.task_assigned') . ': ' . $this->ticket->ticket_no)
            ->line(__('ui.priority') . ': ' . $this->ticket->priority?->getLabel())
            ->line(__('ui.area') . ': ' . $this->ticket->area?->name);
    }

    /**
     * Filament-compatible payload — the database notifications bell renders
     * the result of FilamentNotification::getDatabaseMessage() directly.
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — ' . __('ui.task_assigned'))
            ->body($this->ticket->area?->name . ' / ' . ($this->ticket->priority?->getLabel() ?? ''))
            ->icon('heroicon-o-user-circle')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label(__('ui.ticket_detail'))
                    ->url(route('filament.dashboard.resources.tickets.view', $this->ticket))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
