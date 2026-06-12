<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the ticket CREATOR when the ticket transitions → RESOLVED.
 * Mail is always sent (ignores mail_enabled config).
 */
class TicketResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function getNotificationType(): string
    {
        return 'ticket_resolved';
    }

    public function via(object $notifiable): array
    {
        // Always mail for this event regardless of mail_enabled config.
        $channels = ['mail'];
        if (!method_exists($notifiable, 'wantsNotification')
            || $notifiable->wantsNotification($this->getNotificationType(), 'database')) {
            $channels[] = 'database';
        }
        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->ticket->ticket_no . ' — Talep Çözüldü')
            ->view('mail.ticket-event', [
                'ticket'           => $this->ticket,
                'notifiableName'   => $notifiable->name ?? $notifiable->email ?? '',
                'eventTitle'       => 'Talep Çözüldü',
                'eventDescription' => __('ui.notification_resolved_desc'),
                'headerColor'      => '#198754',
                'headerColorDark'  => '#146c43',
                'note'             => null,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — Çözüldü')
            ->body(($this->ticket->area?->name ?? '—') . ' • ' . ($this->ticket->priority?->getLabel() ?? ''))
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label('Talebi Aç')
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
