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
        return \App\Support\NotificationChannels::resolve($notifiable, $this->getNotificationType());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->ticket->sla_deadline?->format('d.m.Y H:i');
        return (new MailMessage)
            ->subject($this->ticket->ticket_no . ' — ⚠️ SLA Uyarısı')
            ->view('emails.ticket-notification', [
                'ticketNo'         => $this->ticket->ticket_no,
                'eventDescription' => 'Bu talebin SLA süresi dolmak üzere.',
                'area'             => $this->ticket->area?->name,
                'priority'         => $this->ticket->priority?->getLabel(),
                'status'           => $this->ticket->status?->getLabel(),
                'assignee'         => $this->ticket->employee?->name,
                'url'              => url('/tickets/' . $this->ticket->id),
                'note'             => $deadline ? 'Son tarih: ' . $deadline : null,
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
