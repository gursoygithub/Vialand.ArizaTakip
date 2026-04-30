<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCancelledNotification extends Notification implements ShouldQueue
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
            ->subject($this->ticket->ticket_no . ' — iptal edildi')
            ->line($this->ticket->ticket_no . ' iptal edildi.');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — iptal edildi')
            ->body($this->ticket->area?->name ?? '—')
            ->icon('heroicon-o-x-circle')
            ->iconColor('danger')
            ->actions([
                Action::make('view')
                    ->label(__('ui.ticket_detail'))
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
