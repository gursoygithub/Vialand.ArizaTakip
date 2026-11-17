<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\ExemptionStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TeamTicketEventEnum;
use App\Enums\UserStatusEnum;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SlaExemption;
use App\Models\SubArea;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketSlaCycle;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Services\FcmService;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRequestModelSeeder;
use Database\Seeders\DemoTicketScenarioSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DemoTicketScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18 11:00:00'));
        Notification::fake();
        $this->mock(FcmService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser', 'sendToUsers')->andReturnNull();
        });

        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->create());

        Employee::factory()->count(12)->create(['company_id' => Company::factory(), 'status' => ActiveStatusEnum::ACTIVE]);
        $this->seed(DemoRequestModelSeeder::class);

        $mallTeam = Team::factory()->create([
            'name' => 'VIA AVM Elektrik Ekibi',
            'ticket_type_id' => TicketType::where('name', 'Elektrik')->sole()->id,
            'supervisor_employee_id' => Employee::factory()->create(['status' => ActiveStatusEnum::ACTIVE])->id,
            'allow_self_assign' => true,
            'is_active' => true,
        ]);
        $mallTeam->members()->attach(Employee::factory()->create(['status' => ActiveStatusEnum::ACTIVE])->id);
        foreach (TaskPriorityEnum::cases() as $priority) {
            [$response, $resolution] = Team::DEFAULT_SLA_MINUTES[$priority->value];
            $mallTeam->slaPolicies()->updateOrCreate(['priority' => $priority->value], ['response_minutes' => $response, 'resolution_minutes' => $resolution, 'success_threshold' => 80]);
        }

        Employee::factory()->count(3)->create(['status' => ActiveStatusEnum::ACTIVE]);
        Employee::all()->each(fn (Employee $employee) => User::factory()->create([
            'email' => $employee->email,
            'username' => 'u'.$employee->id,
            'status' => UserStatusEnum::ACTIVE,
        ]));

        SubArea::factory()->for(Area::factory()->create(['name' => 'TEMAPARK']))->create();
        SubArea::factory()->for(Area::factory()->create(['name' => 'VIALAND AVM']))->create();

        $this->systemAdmin = User::factory()->create(['email' => 'sa@example.com', 'status' => UserStatusEnum::ACTIVE]);
        $this->systemAdmin->assignRole('super_admin');

        $this->outsider = User::factory()->create(['status' => UserStatusEnum::ACTIVE]);
        $this->outsider->givePermissionTo(['sla.exemption.approve', 'ticket.sla.escalation']);

        $this->escalation(0);
    }

    private User $outsider;

    private User $systemAdmin;

    private function escalation(int $hours): void
    {
        $settings = app(GeneralSettings::class);
        $settings->sla_escalation_hours = $hours;
        $settings->save();
    }

    /**
     * @return array<string, Ticket>
     */
    private function demoTickets(): array
    {
        return Ticket::query()
            ->where('title', 'like', DemoTicketScenarioSeeder::MARKER.'%')
            ->get()
            ->keyBy(fn (Ticket $ticket): string => str($ticket->title)->after(DemoTicketScenarioSeeder::MARKER)->value())
            ->all();
    }

    public function test_every_scenario_ends_in_the_expected_state(): void
    {
        $this->seed(DemoTicketScenarioSeeder::class);

        $tickets = $this->demoTickets();
        $this->assertCount(22, $tickets);

        $expected = [
            'Yeni talep — ekipte bekliyor' => TaskStatusEnum::OPEN,
            'Atandı — işe başlanmadı' => TaskStatusEnum::ASSIGNED,
            'Üzerinde çalışılıyor — yorumlu' => TaskStatusEnum::IN_PROGRESS,
            'SLA uyarısı — süre %80 doldu' => TaskStatusEnum::IN_PROGRESS,
            'Yanıt süresi aşıldı — kimse almadı' => TaskStatusEnum::OPEN,
            'Çözüm süresi aşıldı' => TaskStatusEnum::IN_PROGRESS,
            'Beklemede — parça bekleniyor' => TaskStatusEnum::ON_HOLD,
            'Beklemede — talep sahibinden bilgi' => TaskStatusEnum::ON_HOLD,
            'Çözüldü — talep sahibi onayı bekleniyor' => TaskStatusEnum::RESOLVED,
            'Kapatıldı — 5 yıldız' => TaskStatusEnum::CLOSED,
            'Kapatıldı — düşük puan' => TaskStatusEnum::CLOSED,
            'Çözüm reddedildi — tekrar çalışılıyor' => TaskStatusEnum::IN_PROGRESS,
            'İptal edildi — talep sahibi vazgeçti' => TaskStatusEnum::CANCELLED,
            'Reddedildi — kapsam dışı' => TaskStatusEnum::REJECTED,
            'Başka ekibe aktarıldı' => TaskStatusEnum::ASSIGNED,
            'Öncelik yükseltildi' => TaskStatusEnum::OPEN,
            'SLA ihlali — geç çözüldü, onay bekliyor' => TaskStatusEnum::RESOLVED,
            'SLA ihlali — geç çözüldü ve kapandı' => TaskStatusEnum::CLOSED,
            'SLA ihlali — muafiyet onaylandı' => TaskStatusEnum::CLOSED,
            'SLA ihlali — muafiyet onay bekliyor' => TaskStatusEnum::RESOLVED,
            'Otomatik kapandı — onay verilmedi' => TaskStatusEnum::CLOSED,
        ];

        foreach ($expected as $title => $status) {
            $this->assertSame($status, $tickets[$title]->status, $title);
        }

        $cycle = fn (string $title): TicketSlaCycle => TicketSlaCycle::where('ticket_id', $tickets[$title]->id)->orderByDesc('cycle_no')->first();

        $this->assertContains($tickets['Yeniden açıldı']->status, [TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED]);
        $this->assertSame(5, $cycle('Kapatıldı — 5 yıldız')->satisfaction_score);
        $this->assertSame(2, $cycle('Kapatıldı — düşük puan')->satisfaction_score);
        $this->assertSame('VIA AVM Elektrik Ekibi', $tickets['Başka ekibe aktarıldı']->team->name);
        $this->assertSame(TaskPriorityEnum::Urgent, $tickets['Öncelik yükseltildi']->priority);
        $this->assertNotNull($cycle('SLA uyarısı — süre %80 doldu')->warning_sent_at);
        $this->assertFalse($cycle('SLA uyarısı — süre %80 doldu')->resolution_breached);
        $this->assertTrue($cycle('Yanıt süresi aşıldı — kimse almadı')->response_breached);
        $this->assertTrue($cycle('Çözüm süresi aşıldı')->resolution_breached);
        $this->assertTrue($cycle('SLA ihlali — geç çözüldü, onay bekliyor')->resolution_breached);
        $this->assertTrue($cycle('SLA ihlali — geç çözüldü ve kapandı')->resolution_breached);

        $approved = SlaExemption::where('ticket_id', $tickets['SLA ihlali — muafiyet onaylandı']->id)->sole();
        $this->assertSame(ExemptionStatusEnum::Approved, $approved->status);
        $this->assertSame($this->systemAdmin->id, $approved->decided_by);
        $this->assertTrue($cycle('SLA ihlali — muafiyet onaylandı')->exempted);
        $this->assertSame(ExemptionStatusEnum::Pending, SlaExemption::where('ticket_id', $tickets['SLA ihlali — muafiyet onay bekliyor']->id)->sole()->status);
        $this->assertSame(2, SlaExemption::count());

        $this->assertTrue(now()->equalTo(CarbonImmutable::parse('2026-11-18 11:00:00')), 'the seeder must give the clock back');
    }

    /**
     * Run like `artisan db:seed`: nobody is logged in. Empty actors are
     * system steps such as the automatic close.
     */
    public function test_only_people_of_the_demo_teams_are_involved_or_notified(): void
    {
        $panelUser = auth()->user();
        Auth::forgetUser();

        $this->seed(DemoTicketScenarioSeeder::class);

        $teamPeople = Team::with('supervisor.user', 'members.user')->get()
            ->flatMap(fn (Team $team) => $team->members->pluck('user')->push($team->supervisor->user))
            ->filter()
            ->pluck('id');

        $involved = Ticket::query()->where('title', 'like', DemoTicketScenarioSeeder::MARKER.'%')->pluck('created_by')
            ->merge(DB::table('ticket_status_histories')->pluck('changed_by'))
            ->filter()
            ->unique();

        $this->assertEmpty($involved->diff($teamPeople->push($this->systemAdmin->id))->all());
        Notification::assertNothingSentTo($panelUser);

        $outsiderEvents = Notification::sent($this->outsider, TeamTicketNotification::class)
            ->map(fn (TeamTicketNotification $notification): TeamTicketEventEnum => $notification->event)
            ->unique()
            ->values()
            ->all();
        $this->assertSame([TeamTicketEventEnum::ExemptionRequested], $outsiderEvents, 'an outside approver only gets the exemption request bell');
    }

    public function test_it_refuses_to_run_while_escalation_is_on(): void
    {
        $this->escalation(4);

        try {
            $this->seed(DemoTicketScenarioSeeder::class);
            $this->fail('The seeder should refuse to run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('eskalasyon', $exception->getMessage());
        }

        $this->assertSame(0, Ticket::count());
    }

    public function test_running_again_replaces_the_previous_demo_set(): void
    {
        $this->seed(DemoTicketScenarioSeeder::class);
        $firstIds = collect($this->demoTickets())->pluck('id');

        $this->seed(DemoTicketScenarioSeeder::class);

        $this->assertCount(22, $this->demoTickets());
        $this->assertSame(22, Ticket::onlyTrashed()->whereIn('id', $firstIds)->count());
        $this->assertSame(0, TicketSlaCycle::whereIn('ticket_id', $firstIds)->count());
        $this->assertSame(0, SlaExemption::whereIn('ticket_id', $firstIds)->count());
    }
}
