<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Services\SupervisorRoleService;
use App\Services\TicketWorkflowService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Tests\TestCase;

/**
 * AD keeps the TC kimlik no in employeeID, so an account finds its person
 * even when the HR e-mail is empty or personal (and back again).
 */
class AccountByTcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * An AD account whose HR record has a personal e-mail.
     *
     * @return array{0: Employee, 1: User}
     */
    private function personWithPersonalHrEmail(string $tc): array
    {
        $employee = Employee::factory()->create(['tc_no' => $tc, 'email' => 'kisi'.$tc.'@gmail.com', 'status' => ActiveStatusEnum::ACTIVE]);
        $user = User::factory()->create([
            'tc_no' => $tc,
            'email' => 'kisi'.$tc.'@example.com',
            'username' => 'u'.$tc,
            'status' => UserStatusEnum::ACTIVE,
        ]);

        return [$employee, $user];
    }

    public function test_only_an_eleven_digit_value_counts_as_tc(): void
    {
        $this->assertSame('12345678901', UserService::tcFrom('12345678901'));
        $this->assertSame('12345678901', UserService::tcFrom(' 123 4567 8901 '));
        $this->assertNull(UserService::tcFrom('A12345'));
        $this->assertNull(UserService::tcFrom(''));
        $this->assertNull(UserService::tcFrom(null));
    }

    public function test_login_stores_the_tc_from_ad_and_links_by_it(): void
    {
        $employee = Employee::factory()->create(['tc_no' => '11111111110', 'email' => '', 'status' => ActiveStatusEnum::ACTIVE]);
        $ldap = (new LdapUser)->setRawAttributes([
            'samaccountname' => ['ali.veli'],
            'mail' => ['ali.veli@example.com'],
            'employeeid' => ['11111111110'],
            'displayname' => ['Ali Veli'],
        ]);

        $attributes = (new \ReflectionMethod(UserService::class, 'mapLdapAttributes'))
            ->invoke(app(UserService::class), $ldap, 'secret');

        $this->assertSame('11111111110', $attributes['tc_no']);
        $this->assertSame($employee->employee_id, $attributes['employee_id']);
    }

    public function test_account_finds_its_person_by_tc_when_hr_email_differs(): void
    {
        [$employee, $user] = $this->personWithPersonalHrEmail('22222222220');
        $other = Employee::factory()->create(['tc_no' => '33333333330', 'email' => $user->email]);

        $this->assertSame($employee->id, $user->employee->id, 'TC wins over a same-email record of someone else');
        $this->assertSame([$employee->id], $user->employeeIds());

        $noTc = User::factory()->create(['email' => 'eposta@example.com', 'tc_no' => null]);
        $byEmail = Employee::factory()->create(['email' => 'eposta@example.com']);
        $this->assertSame($byEmail->id, $noTc->employee->id, 'without a TC the e-mail still links');
        $this->assertNotNull($other);
    }

    public function test_supervisor_with_a_personal_hr_email_still_leads_and_is_notified(): void
    {
        [$supervisorEmployee, $supervisor] = $this->personWithPersonalHrEmail('44444444440');
        $requester = User::factory()->create(['email' => 'talep@example.com', 'username' => 'talep', 'status' => UserStatusEnum::ACTIVE]);
        $this->actingAs($requester);
        $team = Team::factory()->create(['supervisor_employee_id' => $supervisorEmployee->id]);

        $ticket = app(TicketWorkflowService::class)->open($requester, $team, TaskPriorityEnum::High, 'Klima arızası.');

        $this->assertSame($supervisor->id, $supervisorEmployee->panelUser()->id);
        $this->assertTrue(app(TicketWorkflowService::class)->isSupervisor($ticket, $supervisor));
        $this->assertTrue(Ticket::query()->visibleInTeamWorkflow($supervisor)->whereKey($ticket->id)->exists());
        Notification::assertSentTo($supervisor, TeamTicketNotification::class, fn (TeamTicketNotification $n): bool => $n->event === TeamTicketEventEnum::Opened);
        $this->assertTrue($supervisor->fresh()->hasRole(SupervisorRoleService::ROLE));
    }

    public function test_tc_is_never_serialised(): void
    {
        [, $user] = $this->personWithPersonalHrEmail('55555555550');

        $this->assertArrayNotHasKey('tc_no', $user->toArray());
    }
}
