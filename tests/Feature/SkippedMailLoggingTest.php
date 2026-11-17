<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SkippedMailLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function mailNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<int, string>
             */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->line('Test');
            }
        };
    }

    public function test_mail_to_a_user_without_email_is_logged(): void
    {
        Log::spy();
        $user = User::factory()->create(['email' => '']);

        $user->notify($this->mailNotification());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Mail skipped')
                && $context['notifiable_id'] === $user->id)
            ->once();
    }

    public function test_mail_to_a_user_with_email_is_not_logged(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $user->notify($this->mailNotification());

        Log::shouldNotHaveReceived('warning');
    }
}
