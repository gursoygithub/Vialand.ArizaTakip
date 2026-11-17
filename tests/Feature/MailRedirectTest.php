<?php

namespace Tests\Feature;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
    }

    private function send(): Email
    {
        Mail::raw('Merhaba', function (Message $message): void {
            $message->to('amir@example.com')
                ->cc('teknisyen@example.com')
                ->bcc('gizli@example.com')
                ->subject('TKT-2026-00001 — Yeni talep');
        });

        return app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): string => $address->getAddress(), $addresses);
    }

    public function test_all_mail_goes_to_the_redirect_address_when_set(): void
    {
        config(['mail.always_to' => 'demo@example.com']);

        $email = $this->send();

        $this->assertSame(['demo@example.com'], $this->addresses($email->getTo()));
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
        $this->assertSame(
            '[→ amir@example.com, teknisyen@example.com, gizli@example.com] TKT-2026-00001 — Yeni talep',
            $email->getSubject(),
        );
    }

    public function test_mail_is_untouched_without_a_redirect_address(): void
    {
        config(['mail.always_to' => null]);

        $email = $this->send();

        $this->assertSame(['amir@example.com'], $this->addresses($email->getTo()));
        $this->assertSame(['teknisyen@example.com'], $this->addresses($email->getCc()));
        $this->assertSame('TKT-2026-00001 — Yeni talep', $email->getSubject());
    }
}
