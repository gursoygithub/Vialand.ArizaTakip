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
        // Recipient is the ticket creator — describe the handover in third
        // person; "old → new" goes into the note box.
        return (new MailMessage)
            ->subject($this->ticket->ticket_no . ' — Devredildi')
            ->view('emails.ticket-notification', [
                'ticketNo'         => $this->ticket->ticket_no,
                'eventDescription' => 'Talep yeni bir personele devredildi.',
                'area'             => $this->ticket->area?->name,
                'priority'         => $this->ticket->priority?->getLabel(),
                'status'           => $this->ticket->status?->getLabel(),
                'assignee'         => $this->newName,
                'url'              => url('/tickets/' . $this->ticket->id),
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
