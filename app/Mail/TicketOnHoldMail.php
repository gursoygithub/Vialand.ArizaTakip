<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the group supervisor when a ticket they oversee is put ON_HOLD.
 * Uses the shared ticket-event.blade.php template.
 */
class TicketOnHoldMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User   $actor,
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->ticket->ticket_no
                . ' • Beklemede — '
                . ($this->ticket->group?->name ?? $this->ticket->area?->name ?? ''),
        );
    }

    public function content(): Content
    {
        $actorName = $this->actor->employee?->name ?? $this->actor->name ?? '—';

        return new Content(
            view: 'mail.ticket-event',
            with: [
                'ticket'           => $this->ticket,
                'notifiableName'   => '',   // resolved at send time via To: header
                'eventTitle'       => 'Talep Beklemede',
                'eventDescription' => $actorName . ' talebi beklemeye aldı.',
                'headerColor'      => '#d97706',
                'headerColorDark'  => '#b45309',
                'note'             => $this->note,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
