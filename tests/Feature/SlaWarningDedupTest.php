<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\UserStatusEnum;
use App\Jobs\CheckSlaBreaches;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\SlaWarningNotification;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The 80% SLA warning must go out at most once per ticket per day, no matter
 * what the recipients' bell (database) preferences are. Before the
 * `tickets.sla_warning_sent_at` column, the de-dupe looked for a row in the
 * `notifications` table, so recipients who had muted the `sla_warning` bell
 * received the mail + FCM push again on every 5-minute run.
 */
class SlaWarningDedupTest extends TestCase
{
    use RefreshDatabase;

    private User $assigneeUser;

    private User $creator;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['username' => 'setup_user']));

        $email = fake()->unique()->safeEmail();
        $this->assigneeUser = User::factory()->create([
            'email' => $email,
            'username' => 'assignee_'.uniqid(),
            'status' => UserStatusEnum::ACTIVE,
        ]);
        $assignee = Employee::factory()->create([
            'email' => $email,
            'status' => ActiveStatusEnum::ACTIVE,
        ]);

        $this->creator = User::factory()->create(['username' => 'creator_'.uniqid()]);

        $this->ticket = Ticket::factory()->create([
            'employee_id' => $assignee->id,
            'status' => TaskStatusEnum::ASSIGNED,
            'created_by' => $this->creator->id,
        ]);

        $this->putTicketInWarningWindow();
    }

    /**
     * 85 of a 100-minute window elapsed → past the 80% threshold, not breached.
     * Written straight to the DB so TicketObserver::saving does not interfere.
     */
    private function putTicketInWarningWindow(): void
    {
        DB::table('tickets')->where('id', $this->ticket->id)->update([
            'created_at' => now()->subMinutes(85),
            'sla_deadline' => now()->addMinutes(15),
            'sla_breached' => false,
        ]);
    }

    private function muteWarningBellFor(User ...$users): void
    {
        foreach ($users as $user) {
            UserNotificationPreference::create([
                'user_id' => $user->id,
                'notification_type' => 'sla_warning',
                'mail_enabled' => true,
                'database_enabled' => false,
            ]);
        }
    }

    public function test_warning_is_sent_once_when_job_runs_repeatedly_on_the_same_day(): void
    {
        Notification::fake();

        (new CheckSlaBreaches)->handle();
        (new CheckSlaBreaches)->handle();
        (new CheckSlaBreaches)->handle();

        Notification::assertSentToTimes($this->assigneeUser, SlaWarningNotification::class, 1);
        Notification::assertSentToTimes($this->creator, SlaWarningNotification::class, 1);
    }

    public function test_warning_is_not_repeated_when_every_recipient_muted_the_bell(): void
    {
        $this->muteWarningBellFor($this->assigneeUser, $this->creator);

        (new CheckSlaBreaches)->handle();
        (new CheckSlaBreaches)->handle();

        $this->assertDatabaseMissing('notifications', ['type' => SlaWarningNotification::class]);
        $this->assertTrue($this->ticket->fresh()->sla_warning_sent_at->isToday());

        Notification::fake();

        (new CheckSlaBreaches)->handle();

        Notification::assertNothingSent();
    }

    public function test_fcm_push_is_sent_once_per_day(): void
    {
        $this->mock(FcmService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUsers')->once();
        });

        Notification::fake();

        (new CheckSlaBreaches)->handle();
        (new CheckSlaBreaches)->handle();
    }

    public function test_warning_is_sent_again_on_the_next_day_while_still_in_the_window(): void
    {
        Notification::fake();

        (new CheckSlaBreaches)->handle();

        $this->travel(1)->days();
        DB::table('tickets')->where('id', $this->ticket->id)->update([
            'created_at' => now()->subMinutes(85),
            'sla_deadline' => now()->addMinutes(15),
        ]);

        (new CheckSlaBreaches)->handle();

        Notification::assertSentToTimes($this->assigneeUser, SlaWarningNotification::class, 2);
    }

    public function test_ticket_below_the_threshold_is_not_stamped(): void
    {
        Notification::fake();

        DB::table('tickets')->where('id', $this->ticket->id)->update([
            'created_at' => now()->subMinutes(10),
            'sla_deadline' => now()->addMinutes(90),
        ]);

        (new CheckSlaBreaches)->handle();

        Notification::assertNothingSent();
        $this->assertNull($this->ticket->fresh()->sla_warning_sent_at);
    }
}
