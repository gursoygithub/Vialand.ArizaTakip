<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\ExemptionReasonEnum;
use App\Enums\ExemptionStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Pages\TeamPerformanceReport;
use App\Filament\Resources\SlaExemptionResource\Pages\ListSlaExemptions;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Services\SlaExemptionService;
use App\Services\TeamPerformanceService;
use App\Services\TicketWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satisfaction, SLA exemptions and the team performance report. The team
 * runs 7/24; Urgent = 15 min response / 120 min resolution.
 */
class TeamPerformanceAndExemptionTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflowService $workflow;

    private Team $team;

    private User $requester;

    private User $supervisor;

    private User $tech1;

    private User $tech2;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00'));
        Notification::fake();
        $this->seed(PermissionSeeder::class);

        $this->requester = $this->person()[1];
        [$supervisorEmployee, $this->supervisor] = $this->person();
        [$tech1Employee, $this->tech1] = $this->person();
        [$tech2Employee, $this->tech2] = $this->person();
        $this->approver = $this->person()[1];
        $this->approver->givePermissionTo(SlaExemptionService::PERMISSION_APPROVE);

        $this->actingAs($this->requester);
        $this->team = Team::factory()->create(['name' => 'Teknik', 'supervisor_employee_id' => $supervisorEmployee->id]);
        $this->team->members()->attach([$tech1Employee->id, $tech2Employee->id]);
        $this->workflow = app(TicketWorkflowService::class);
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function person(): array
    {
        $email = fake()->unique()->safeEmail();
        $user = User::factory()->create(['email' => $email, 'username' => 'u'.fake()->unique()->numerify('#######'), 'status' => UserStatusEnum::ACTIVE]);

        return [Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE]), $user];
    }

    private function open(): Ticket
    {
        return $this->workflow->open($this->requester, $this->team, TaskPriorityEnum::Urgent, 'Arıza açıklaması.');
    }

    private function startedBy(User $tech, ?Ticket $ticket = null): Ticket
    {
        $ticket = $this->workflow->assign($ticket ?? $this->open(), $tech->employee, $this->supervisor);

        return $this->workflow->startWork($ticket, $tech);
    }

    private function resolveAndConfirm(Ticket $ticket, User $tech, ?int $score = null, ?string $comment = null): Ticket
    {
        return $this->workflow->confirm($this->workflow->resolve($ticket, $tech), $this->requester, $score, $comment);
    }

    private function breachedByTech1(): Ticket
    {
        $ticket = $this->startedBy($this->tech1);
        $this->travel(150)->minutes();

        return $this->resolveAndConfirm($ticket, $this->tech1);
    }

    private function report(?User $viewer = null): array
    {
        return app(TeamPerformanceService::class)->report(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
            $viewer ?? $this->supervisor,
        );
    }

    // ── Satisfaction ────────────────────────────────────────────────────

    public function test_requester_rates_the_service_when_confirming(): void
    {
        $ticket = $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1, 5);

        $cycle = $ticket->currentCycle;
        $this->assertSame(5, $cycle->satisfaction_score);
        $this->assertNotNull($cycle->rated_at);
    }

    public function test_low_score_needs_a_comment_and_score_must_be_in_range(): void
    {
        $ticket = $this->workflow->resolve($this->startedBy($this->tech1), $this->tech1);

        foreach ([[2, null], [0, 'x'], [6, 'x']] as [$score, $comment]) {
            try {
                $this->workflow->confirm($ticket->fresh(), $this->requester, $score, $comment);
                $this->fail("score {$score} accepted");
            } catch (TicketWorkflowException) {
            }
        }

        $ticket = $this->workflow->confirm($ticket->fresh(), $this->requester, 2, 'Geç geldiler.');
        $this->assertSame('Geç geldiler.', $ticket->currentCycle->satisfaction_comment);
    }

    public function test_rating_is_optional(): void
    {
        $ticket = $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1);

        $this->assertNull($ticket->currentCycle->satisfaction_score);
    }

    // ── Exemptions ──────────────────────────────────────────────────────

    public function test_person_charged_with_the_breach_can_request_an_exemption(): void
    {
        $cycle = $this->breachedByTech1()->currentCycle;
        $service = app(SlaExemptionService::class);

        $this->assertTrue($service->canRequest($cycle, $this->tech1));
        $this->assertTrue($service->canRequest($cycle, $this->supervisor));
        $this->assertFalse($service->canRequest($cycle, $this->tech2));
        $this->assertFalse($service->canRequest($cycle, $this->requester));

        $exemption = $service->request($cycle, $this->tech1, ExemptionReasonEnum::PowerOutage, 'Bölgede 2 saat elektrik yoktu.');

        $this->assertSame(ExemptionStatusEnum::Pending, $exemption->status);
        $this->assertFalse($service->canRequest($cycle->fresh(), $this->supervisor), 'one open request per cycle');
        Notification::assertSentTo($this->approver, TeamTicketNotification::class,
            fn (TeamTicketNotification $n): bool => $n->event === TeamTicketEventEnum::ExemptionRequested);
    }

    public function test_on_time_cycle_cannot_be_exempted(): void
    {
        $cycle = $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1)->currentCycle;

        $this->assertFalse(app(SlaExemptionService::class)->canRequest($cycle, $this->supervisor));
    }

    public function test_nobody_clears_their_own_or_their_teams_breach(): void
    {
        $cycle = $this->breachedByTech1()->currentCycle;
        $service = app(SlaExemptionService::class);
        $exemption = $service->request($cycle, $this->supervisor, ExemptionReasonEnum::Supplier, 'Parça gelmedi.');

        foreach ([$this->supervisor, $this->tech1, $this->tech2] as $insider) {
            $insider->givePermissionTo(SlaExemptionService::PERMISSION_APPROVE);
            $this->assertFalse($service->canDecide($exemption, $insider->fresh()), $insider->username);
        }

        $this->assertFalse($service->canDecide($exemption, $this->requester), 'no permission');
        $this->assertTrue($service->canDecide($exemption, $this->approver));
    }

    public function test_approval_takes_the_cycle_out_of_performance(): void
    {
        $cycle = $this->breachedByTech1()->currentCycle;
        $service = app(SlaExemptionService::class);
        $exemption = $service->request($cycle, $this->tech1, ExemptionReasonEnum::ExternalFactor, 'Yangın tatbikatı.');

        $service->approve($exemption, $this->approver, 'Uygun.');

        $this->assertTrue($cycle->fresh()->exempted);
        $this->assertSame(ExemptionStatusEnum::Approved, $exemption->fresh()->status);
        Notification::assertSentTo($this->tech1, TeamTicketNotification::class,
            fn (TeamTicketNotification $n): bool => $n->event === TeamTicketEventEnum::ExemptionDecided);

        $report = $this->report();
        $this->assertSame(0, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['exempted']);
    }

    public function test_rejection_needs_a_reason_and_keeps_the_breach(): void
    {
        $cycle = $this->breachedByTech1()->currentCycle;
        $service = app(SlaExemptionService::class);
        $exemption = $service->request($cycle, $this->tech1, ExemptionReasonEnum::WrongPriority, 'Acil değildi.');

        try {
            $service->reject($exemption, $this->approver, ' ');
            $this->fail('rejected without a reason');
        } catch (TicketWorkflowException) {
        }

        $service->reject($exemption->fresh(), $this->approver, 'Öncelik doğru seçilmiş.');

        $this->assertFalse($cycle->fresh()->exempted);
        $this->assertSame(1, $this->report()['summary']['breached']);
    }

    public function test_exemption_list_shows_requests_to_approvers_and_own_requests_to_requesters(): void
    {
        $cycle = $this->breachedByTech1()->currentCycle;
        $exemption = app(SlaExemptionService::class)->request($cycle, $this->tech1, ExemptionReasonEnum::Supplier, 'Parça.');

        $this->actingAs($this->approver);
        Livewire::test(ListSlaExemptions::class)
            ->assertCanSeeTableRecords([$exemption])
            ->callTableAction('approve', $exemption, data: ['note' => 'Tamam']);
        $this->assertSame(ExemptionStatusEnum::Approved, $exemption->fresh()->status);

        $this->actingAs($this->tech1);
        Livewire::test(ListSlaExemptions::class)
            ->filterTable('status', ExemptionStatusEnum::Approved->value)
            ->assertCanSeeTableRecords([$exemption])
            ->assertTableActionHidden('approve', $exemption);
    }

    // ── Report ──────────────────────────────────────────────────────────

    public function test_report_charges_each_breach_to_the_right_person_or_team(): void
    {
        // 1) tech1 on time, rated 4 — later reopened (quality figure for tech1).
        $onTime = $this->resolveAndConfirm($this->startedBy($this->tech1), $this->tech1, 4);
        $this->travel(10)->minutes();
        $this->workflow->reopen($onTime, $this->requester, 'Tekrarladı.');

        // 2) tech1 late → personal breach.
        $this->breachedByTech1();

        // 3) tech1 holds until 20 min are left, hands to tech2, who is late → handover (team).
        $ticket = $this->startedBy($this->tech1);
        $this->travel(100)->minutes();
        $ticket = $this->workflow->startWork($this->workflow->assign($ticket, $this->tech2->employee, $this->supervisor), $this->tech2);
        $this->travel(60)->minutes();
        $this->resolveAndConfirm($ticket, $this->tech2);

        // 4) nobody assigned when the deadline passed → dispatch delay (team).
        $ticket = $this->open();
        $this->travel(130)->minutes();
        $this->workflow->flagResolutionBreach($ticket->currentCycle);
        $this->resolveAndConfirm($this->startedBy($this->tech2, $ticket), $this->tech2, 2, 'Çok geç.');

        // 5) cancelled after the breach → never counted, but listed.
        $ticket = $this->startedBy($this->tech2);
        $this->travel(200)->minutes();
        $this->workflow->cancel($ticket, $this->supervisor, 'Vazgeçildi.');

        $report = $this->report();
        $summary = $report['summary'];

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['on_time']);
        $this->assertSame(3, $summary['breached']);
        $this->assertSame(25.0, $summary['compliance']);
        $this->assertSame(1, $summary['personal_breaches']);
        $this->assertSame(1, $summary['handover_breaches']);
        $this->assertSame(1, $summary['dispatch_delays']);
        $this->assertSame(1, $summary['cancelled_after_breach']);
        $this->assertSame(1, $summary['reopened']);
        $this->assertSame(3.0, $summary['satisfaction']);

        $people = collect($report['people'])->keyBy('name');
        $tech1 = $people[$this->tech1->employee->name];
        $tech2 = $people[$this->tech2->employee->name];

        $this->assertSame(1, $tech1['personal_breaches']);
        $this->assertSame(2, $tech1['total'], 'resolved two, charged once (same cycle)');
        $this->assertSame(50.0, $tech1['compliance']);
        $this->assertSame(1, $tech1['reopened']);

        $this->assertSame(0, $tech2['personal_breaches'], 'handover and dispatch are not personal');
        $this->assertSame(100.0, $tech2['compliance']);
    }

    public function test_supervisor_sees_only_their_teams(): void
    {
        $this->breachedByTech1();
        [$otherSupervisorEmployee, $otherSupervisor] = $this->person();
        Team::factory()->create(['supervisor_employee_id' => $otherSupervisorEmployee->id]);

        $this->assertSame(1, $this->report()['summary']['total']);
        $this->assertSame(0, $this->report($otherSupervisor)['summary']['total']);
    }

    public function test_report_page_is_permission_gated(): void
    {
        $this->breachedByTech1();

        $this->actingAs($this->tech1);
        $this->get(TeamPerformanceReport::getUrl())->assertForbidden();

        $this->supervisor->givePermissionTo('page_TeamPerformanceReport');
        $this->actingAs($this->supervisor);
        $this->get(TeamPerformanceReport::getUrl())
            ->assertOk()
            ->assertSee('Teknik')
            ->assertSee($this->tech1->employee->name);
    }
}
