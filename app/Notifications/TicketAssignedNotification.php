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
            ->subject($this->ticket->ticket_no . ' — Size Atandı')
            ->view('emails.ticket-notification', [
                'ticketNo'         => $this->ticket->ticket_no,
                'eventDescription' => $this->ticket->ticket_no . ' size atandı.',
                'area'             => $this->ticket->area?->name,
                'priority'         => $this->ticket->priority?->getLabel(),
                'status'           => $this->ticket->status?->getLabel(),
                'assignee'         => $this->ticket->employee?->name,
                'url'              => url('/tickets/' . $this->ticket->id),
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
