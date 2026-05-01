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

class TicketCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $author,
        public readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        // Comments are database-only by policy — they happen often and the
        // bell + desktop chime cover them. No email channel.
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no . ' — ' . $this->author->name)
            ->body(\Illuminate\Support\Str::limit($this->body, 140))
            ->icon('heroicon-o-chat-bubble-left')
            ->iconColor('primary')
            ->actions([
                Action::make('view')
                    ->label(__('ui.ticket_detail'))
                    ->url(url('/tickets/' . $this->ticket->id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
