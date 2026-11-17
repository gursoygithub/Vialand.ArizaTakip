<?php

namespace App\Notifications;

use App\Enums\TeamTicketEventEnum;
use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification for every team-ticket event. Who receives it and on
 * which channels is decided by TeamTicketNotifier, not here.
 */
class TeamTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels  'database' and/or 'mail'
     */
    public function __construct(
        public Ticket $ticket,
        public TeamTicketEventEnum $event,
        public array $channels,
        public ?string $actorName = null,
        public ?string $note = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public static function urlFor(Ticket $ticket): string
    {
        return $ticket->panelUrl();
    }

    public function body(): string
    {
        return collect([
            $this->ticket->ticketType?->name,
            $this->ticket->team?->name,
            $this->actorName ? __('ui.event_by', ['name' => $this->actorName]) : null,
        ])->filter()->implode(' • ');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->ticket->ticket_no.' — '.$this->event->title())
            ->view('mail.ticket-event', [
                'ticket' => $this->ticket,
                'ticketUrl' => self::urlFor($this->ticket),
                'notifiableName' => $notifiable->name ?? '',
                'eventTitle' => $this->event->title(),
                'eventDescription' => $this->event->description(),
                'headerColor' => $this->event->color(),
                'headerColorDark' => $this->event->color(),
                'note' => $this->note,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->ticket->ticket_no.' — '.$this->event->title())
            ->body($this->body())
            ->icon($this->event->icon())
            ->iconColor($this->event->filamentColor())
            ->actions([
                Action::make('view')
                    ->label(__('ui.event_open_request'))
                    ->url(self::urlFor($this->ticket))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
