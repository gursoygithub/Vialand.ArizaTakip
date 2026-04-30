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
            ->subject('🔴 ' . $this->ticket->ticket_no . ' — ' . __('ui.sla_breached'))
            ->error()
            ->line($this->ticket->ticket_no . ' — ' . __('ui.sla_breached'))
            ->line(__('ui.area') . ': ' . ($this->ticket->area?->name ?? '—'))
            ->line(__('ui.assigned_employee') . ': ' . ($this->ticket->employee?->name ?? '—'));
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
                    ->url(route('filament.dashboard.resources.tickets.view', $this->ticket))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
