<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketClosedNotification extends Notification implements ShouldQueue
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
        $slaResult = $this->ticket->sla_breached ? __('ui.sla_breached') : __('ui.on_time');

        return (new MailMessage)
            ->subject($this->ticket->ticket_no . ' — ' . __('ui.closed'))
            ->line($this->ticket->ticket_no . ' — ' . __('ui.closed'))
            ->line(__('ui.sla_indicator') . ': ' . $slaResult);
    }

    public function toDatabase(object $notifiable): array
    {
        $breached = (bool) $this->ticket->sla_breached;

        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — ' . __('ui.closed'))
            ->body($breached ? __('ui.sla_breached') : __('ui.on_time'))
            ->icon($breached ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->iconColor($breached ? 'danger' : 'success')
            ->actions([
                Action::make('view')
                    ->label(__('ui.ticket_detail'))
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
