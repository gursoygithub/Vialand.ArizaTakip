<?php

namespace App\Notifications;

use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function getNotificationType(): string
    {
        return 'sla_warning';
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
            ->subject($this->ticket->ticket_no . ' — SLA Süresi Dolmak Üzere')
            ->view('mail.ticket-event', [
                'ticket'           => $this->ticket,
                'notifiableName'   => $notifiable->name ?? $notifiable->email ?? '',
                'eventTitle'       => 'SLA Uyarısı',
                'eventDescription' => __('ui.notification_sla_warning_desc'),
                'headerColor'      => '#ffc107',
                'headerColorDark'  => '#d39e00',
                'note'             => null,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — SLA %80')
            ->body($this->ticket->area?->name . ' • ' . __('ui.sla_deadline') . ': ' .
                $this->ticket->sla_deadline?->format('d.m.Y H:i'))
            ->icon('heroicon-o-clock')
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
