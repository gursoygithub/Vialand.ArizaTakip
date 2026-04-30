<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketReopenedNotification extends Notification implements ShouldQueue
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
            ->subject($this->ticket->ticket_no . ' — yeniden açıldı')
            ->line($this->ticket->ticket_no . ' yeniden açıldı.')
            ->line('Bölge: ' . ($this->ticket->area?->name ?? '—'));
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — yeniden açıldı')
            ->body(($this->ticket->area?->name ?? '—') . ' • ' .
                ($this->ticket->priority?->getLabel() ?? ''))
            ->icon('heroicon-o-arrow-path')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label(__('ui.ticket_detail'))
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
