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

    public function getNotificationType(): string
    {
        return 'ticket_closed';
    }

    public function via(object $notifiable): array
    {
        return \App\Support\NotificationChannels::resolve($notifiable, $this->getNotificationType());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->ticket->ticket_no . ' — Kapatıldı')
            ->view('emails.ticket-notification', [
                'ticketNo'         => $this->ticket->ticket_no,
                'eventDescription' => 'Açmış olduğunuz talep kapatıldı.',
                'area'             => $this->ticket->area?->name,
                'priority'         => $this->ticket->priority?->getLabel(),
                'status'           => $this->ticket->status?->getLabel(),
                'assignee'         => $this->ticket->employee?->name,
                'url'              => url('/tickets/' . $this->ticket->id),
                'note'             => $this->ticket->resolution_notes ?: null,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        $finalAt  = $this->ticket->resolved_at ?? $this->ticket->closed_at;
        $breached = $this->ticket->sla_deadline
            && ($finalAt
                ? $finalAt->gt($this->ticket->sla_deadline)
                : now()->gt($this->ticket->sla_deadline));

        $resolutionMins = $this->ticket->assigned_at && $this->ticket->closed_at
            ? (int) $this->ticket->assigned_at->diffInMinutes($this->ticket->closed_at)
            : null;

        $body = ($breached ? __('ui.sla_breached') : __('ui.on_time'))
            . ($resolutionMins !== null ? ' • Çözüm: ' . $resolutionMins . ' dk' : '');

        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — ' . __('ui.closed'))
            ->body($body)
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
