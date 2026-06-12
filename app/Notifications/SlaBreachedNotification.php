<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function getNotificationType(): string
    {
        return 'sla_breach';
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
            ->subject($this->ticket->ticket_no . ' — SLA Süresi Doldu')
            ->view('mail.ticket-event', [
                'ticket'           => $this->ticket,
                'notifiableName'   => $notifiable->name ?? $notifiable->email ?? '',
                'eventTitle'       => 'SLA İhlali',
                'eventDescription' => __('ui.notification_sla_breached_desc'),
                'headerColor'      => '#dc3545',
                'headerColorDark'  => '#b02a37',
                'note'             => null,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — ' . __('ui.sla_breached'))
            ->body(($this->ticket->area?->name ?? '—') . ' • ' .
                __('ui.assigned_employee') . ': ' . ($this->ticket->employee?->name ?? '—'))
            ->icon('heroicon-o-exclamation-triangle')
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
