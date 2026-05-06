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

    public function getNotificationType(): string
    {
        return 'ticket_assigned';
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
            ->subject($this->ticket->ticket_no . ' — Talep Size Atandı')
            ->view('mail.ticket-event', [
                'ticket'           => $this->ticket,
                'notifiableName'   => $notifiable->name ?? $notifiable->email ?? '',
                'eventTitle'       => 'Talep Size Atandı',
                'eventDescription' => 'Aşağıdaki talep size atandı. Lütfen en kısa sürede ilgilenin.',
                'headerColor'      => '#007bff',
                'headerColorDark'  => '#0056b3',
                'note'             => null,
            ]);
    }

    /**
     * Filament-compatible payload — the database notifications bell renders
     * the result of FilamentNotification::getDatabaseMessage() directly.
     */
    public function toDatabase(object $notifiable): array
    {
        $deadline = $this->ticket->sla_deadline?->format('d.m.Y H:i');
        $body = ($this->ticket->area?->name ?? '—')
            . ' • ' . ($this->ticket->priority?->getLabel() ?? '')
            . ($deadline ? ' • SLA: ' . $deadline : '');

        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — size atandı')
            ->body($body)
            ->icon('heroicon-o-user-circle')
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
