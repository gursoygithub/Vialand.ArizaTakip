<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a ticket's CREATOR (when different from the new assignee and the
 * actor) on reassignment. The new assignee gets TicketAssignedNotification
 * separately. Body shows the personnel handover: old → new.
 */
class TicketReassignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $oldName,
        public readonly string $newName,
        public readonly User $actor,
    ) {}

    public function getNotificationType(): string
    {
        return 'ticket_reassigned';
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
            ->subject($this->ticket->ticket_no . ' — Talep Devredildi')
            ->view('mail.ticket-event', [
                'ticket'           => $this->ticket,
                'notifiableName'   => $notifiable->name ?? $notifiable->email ?? '',
                'eventTitle'       => 'Talep Devredildi',
                'eventDescription' => 'Takip ettiğiniz talep yeni bir personele devredildi.',
                'headerColor'      => '#0d6efd',
                'headerColorDark'  => '#0a58ca',
                'note'             => "{$this->oldName} → {$this->newName}",
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' • Personel Değişikliği')
            ->body("{$this->oldName} → {$this->newName}")
            ->icon('heroicon-o-user-group')
            ->iconColor('info')
            ->actions([
                Action::make('view')
                    ->label('Talebi Aç')
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
