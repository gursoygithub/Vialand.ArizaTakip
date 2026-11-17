<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Test environments share real people's data, so with `mail.always_to` set
 * every mail goes to that single inbox instead. The subject keeps the
 * intended recipients so a demo can still show who would have got it.
 */
class RedirectOutgoingMail
{
    public function handle(MessageSending $event): void
    {
        $redirectTo = config('mail.always_to');

        if (blank($redirectTo)) {
            return;
        }

        $message = $event->message;
        $intended = collect([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])
            ->map(fn (Address $address): string => $address->getAddress())
            ->unique()
            ->implode(', ');

        $message->to($redirectTo)->cc()->bcc();

        if ($intended !== '') {
            $message->subject("[→ {$intended}] ".$message->getSubject());
        }
    }
}
