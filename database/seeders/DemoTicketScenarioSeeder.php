<?php

namespace Database\Seeders;

use App\Enums\FormFieldTypeEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\RejectionReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Jobs\ProcessTeamTicketSla;
use App\Models\Area;
use App\Models\SlaExemption;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketSlaCycle;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Presentation set: one team ticket per lifecycle / SLA scenario, built
 * through the real workflow (back-dated with a fake clock) so timelines,
 * SLA cycles and notifications look exactly like real use.
 *
 * Only the people of these teams take part: requesters, assignees and
 * supervisors all come from them. Steps that would reach anyone else (SLA
 * exemption approvers, escalation recipients) are left out, and the seeder
 * refuses to run while SLA escalation is switched on.
 *
 * Works on the teams that already exist (by name). Re-running removes the
 * previous demo tickets first; they are recognised by the title marker,
 * which no screen shows. Old demo tickets are soft-deleted so ticket
 * numbers never get reused.
 *
 * php artisan db:seed --class=DemoTicketScenarioSeeder
 */
class DemoTicketScenarioSeeder extends Seeder
{
    public const MARKER = 'DEMO · ';

    private const TECH = 'Teknik Servis Ekibi';

    private const IT = 'IT Destek Ekibi';

    private const ELECTRIC = 'Elektrik Ekibi';

    private const CLEANING = 'Temizlik Ekibi';

    private const MALL_ELECTRIC = 'VIA AVM Elektrik Ekibi';

    /**
     * Preferred requesters, picked when they are not part of the team.
     *
     * @var array<int, string>
     */
    private const REQUESTER_USERNAMES = ['emirhankaraca', 'fatihsoyler', 'serkanfidanci', 'hakan.aydin', 'sametkose', 'anilhekimoglu'];

    private CarbonImmutable $now;

    private int $requesterTurn = 0;

    /**
     * @var array<string, string>
     */
    private array $results = [];

    public function __construct(
        private TicketWorkflowService $workflow,
        private TicketService $tickets,
        private GeneralSettings $settings,
    ) {}

    public function run(): void
    {
        if ($this->settings->sla_escalation_hours > 0) {
            throw new RuntimeException('SLA eskalasyonu açık (sla_escalation_hours = '.$this->settings->sla_escalation_hours.'); eskalasyon ekip dışındaki yetkililere bildirim gönderir. Önce ayarı 0 yapın.');
        }

        $removed = $this->removePreviousDemo();
        $previousClock = Carbon::getTestNow();
        $this->now = CarbonImmutable::now()->startOfMinute();

        try {
            foreach ($this->scenarios() as $title => [$teamName, $build]) {
                $team = Team::query()->active()->where('name', $teamName)->first();

                if (! $team) {
                    $this->results[$title] = "atlandı: '{$teamName}' bulunamadı";

                    continue;
                }

                $ticket = $build($team, $title);
                $this->results[$title] = $ticket->fresh()->ticket_no.' → '.$ticket->fresh()->status->getLabel();
            }

            $this->at($this->now);
            app()->call([new ProcessTeamTicketSla, 'handle']);
        } finally {
            Carbon::setTestNow($previousClock);
            CarbonImmutable::setTestNow($previousClock ? CarbonImmutable::instance($previousClock) : null);
        }

        $this->command?->info("Önceki demo talepleri kaldırıldı: {$removed}");
        $this->command?->table(['Senaryo', 'Sonuç'], collect($this->results)->map(fn (string $result, string $title): array => [$title, $result])->values()->all());
    }

    /**
     * @return array<string, array{0: string, 1: \Closure(Team, string): Ticket}>
     */
    private function scenarios(): array
    {
        return [
            'Yeni talep — ekipte bekliyor' => [self::IT, function (Team $team, string $title): Ticket {
                return $this->open($team, $title, TaskPriorityEnum::Medium, 20, 'Muhasebe katındaki yazıcı kağıt sıkıştırıyor, çıktı alınamıyor.', ['device_kind' => 'Yazıcı', 'asset_no' => 'YZC-014']);
            }],

            'Atandı — işe başlanmadı' => [self::ELECTRIC, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::High, 90, 'Mutfak bölümündeki priz yanık kokuyor, sigorta atıyor.', ['panel_no' => 'P-07']);

                return $this->take($ticket, $team, 0, 60);
            }],

            'Üzerinde çalışılıyor — yorumlu' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Urgent, 40, 'Çarpışan arabalarda iki araç hareket etmiyor, sıra uzuyor.', ['equipment' => 'Çarpışan Arabalar', 'is_operation_stopped' => true]);
                $ticket = $this->work($ticket, $team, 0, 35, 30);
                $this->comment($ticket, $this->requesterOf($ticket), 'Bekleme alanında çok kalabalık var, öncelikli bakılabilir mi?', 20);
                $this->comment($ticket, $this->assigneeOf($ticket), 'Ünite kapatıldı, kömür fırçaları kontrol ediliyor.', 10);

                return $ticket;
            }],

            'SLA uyarısı — süre %80 doldu' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::High, 420, 'Dönme dolap 4 numaralı kabin kapısı tam kilitlenmiyor.', ['equipment' => 'Dönme Dolap', 'is_operation_stopped' => false]);

                return $this->work($ticket, $team, 1, 400, 380);
            }],

            'Yanıt süresi aşıldı — kimse almadı' => [self::CLEANING, function (Team $team, string $title): Ticket {
                return $this->open($team, $title, TaskPriorityEnum::Urgent, 50, 'Yemek alanında su döküldü, kayma tehlikesi var.');
            }],

            'Çözüm süresi aşıldı' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 2880, 'Su parkı pompa dairesinde sürekli alarm çalıyor.', ['equipment' => 'Su Parkı Pompa Dairesi', 'is_operation_stopped' => false]);

                return $this->work($ticket, $team, 2, 2850, 2820);
            }],

            'Beklemede — parça bekleniyor' => [self::ELECTRIC, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 1440, 'Otopark aydınlatmasının yarısı yanmıyor.', ['panel_no' => 'OTP-2']);
                $ticket = $this->work($ticket, $team, 0, 1420, 1400);

                $this->at($this->ago(1260));

                return $this->workflow->hold($ticket, $this->assigneeOf($ticket), HoldReasonEnum::AwaitingParts, 'Kontaktör siparişi verildi, yarın gelecek.');
            }],

            'Beklemede — talep sahibinden bilgi' => [self::IT, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Low, 300, 'Bilgisayarım sık sık donuyor.', ['device_kind' => 'Bilgisayar']);
                $ticket = $this->work($ticket, $team, 2, 240, 235);

                $this->at($this->ago(180));

                return $this->workflow->hold($ticket, $this->assigneeOf($ticket), HoldReasonEnum::AwaitingRequester, 'Demirbaş numarasını ve hata mesajının ekran görüntüsünü paylaşır mısınız?');
            }],

            'Çözüldü — talep sahibi onayı bekleniyor' => [self::IT, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 1440, 'Gişe bilgisayarında internet bağlantısı kopuyor.', ['device_kind' => 'Ağ / İnternet', 'asset_no' => 'GSE-03']);
                $ticket = $this->work($ticket, $team, 1, 1400, 1380);

                return $this->markResolved($ticket, 180, 'Ağ kablosu ve priz değiştirildi, bağlantı test edildi.');
            }],

            'Kapatıldı — 5 yıldız' => [self::CLEANING, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 2880, 'Ana giriş tuvaletlerinde kâğıt havlu bitmiş.');
                $ticket = $this->work($ticket, $team, 0, 2860, 2850);
                $ticket = $this->markResolved($ticket, 2700, 'Tüm tuvaletlerin malzemeleri tamamlandı.');

                return $this->confirm($ticket, 2600, 5, 'Çok hızlı ilgilenildi, teşekkürler.');
            }],

            'Kapatıldı — düşük puan' => [self::ELECTRIC, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Low, 4320, 'Ofis koridorundaki iki lamba yanıp sönüyor.', ['panel_no' => 'OFS-1']);
                $ticket = $this->work($ticket, $team, 1, 4300, 4290);
                $ticket = $this->markResolved($ticket, 1500, 'Balastlar değiştirildi.');

                return $this->confirm($ticket, 1400, 2, 'Sorun çözüldü ama iki gün beklemek zorunda kaldık.');
            }],

            'Çözüm reddedildi — tekrar çalışılıyor' => [self::IT, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::High, 360, 'Kasa yazılımına giriş yapılamıyor.', ['device_kind' => 'Yazılım / Hesap']);
                $ticket = $this->work($ticket, $team, 0, 350, 345);
                $ticket = $this->markResolved($ticket, 240, 'Şifre sıfırlandı.');

                $this->at($this->ago(180));

                return $this->workflow->rejectResolution($ticket, $this->requesterOf($ticket), 'Yeni şifreyle de giriş yapılamıyor, hesap kilitli görünüyor.');
            }],

            'Yeniden açıldı' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 5760, 'Mini tren rayında makas takılıyor.', ['equipment' => 'Mini Tren', 'is_operation_stopped' => true]);
                $ticket = $this->work($ticket, $team, 1, 5740, 5720);
                $ticket = $this->markResolved($ticket, 5000, 'Makas yağlandı ve ayarı yapıldı.');
                $ticket = $this->confirm($ticket, 4400, 4, null);

                $this->at($this->ago(1440));

                return $this->workflow->reopen($ticket, $this->requesterOf($ticket), 'Aynı makas bugün yine takıldı.');
            }],

            'İptal edildi — talep sahibi vazgeçti' => [self::CLEANING, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Low, 180, 'Toplantı salonu masaları silinmeli.');

                $this->at($this->ago(120));

                return $this->workflow->cancel($ticket, $this->requesterOf($ticket), 'Toplantı iptal oldu, gerek kalmadı.');
            }],

            'Reddedildi — kapsam dışı' => [self::MALL_ELECTRIC, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Medium, 300, 'Kiracı mağazanın vitrin aydınlatması çalışmıyor.', ['panel_no' => 'MGZ-118']);

                $this->at($this->ago(240));

                return $this->workflow->reject($ticket, $this->supervisorOf($team), RejectionReasonEnum::OutOfScope, 'Kiracı alanı; mağazanın kendi elektrikçisi bakmalı.');
            }],

            'Başka ekibe aktarıldı' => [self::ELECTRIC, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::High, 360, 'AVM B girişindeki otomatik kapı açılmıyor.', ['panel_no' => 'AVM-B'], 'VIALAND AVM');
                $target = Team::query()->active()->where('name', self::MALL_ELECTRIC)->firstOrFail();

                $this->at($this->ago(300));
                $ticket = $this->workflow->transfer($ticket, $target, $this->supervisorOf($team), 'Kapı VIA AVM tarafında, ilgili ekibe aktarıldı.');

                return $this->take($ticket, $target, 0, 240);
            }],

            'Öncelik yükseltildi' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Low, 120, 'Korku tüneli çıkışındaki korkuluk gevşemiş.', ['equipment' => 'Korku Tüneli', 'is_operation_stopped' => false]);

                $this->at($this->ago(90));

                return $this->workflow->changePriority($ticket, TaskPriorityEnum::Urgent, $this->supervisorOf($team), 'Ziyaretçi güvenliğini etkiliyor.');
            }],

            'SLA ihlali — geç çözüldü, onay bekliyor' => [self::CLEANING, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Urgent, 600, 'Yemek alanında cam kırıldı, alan kapatılmalı.');
                $ticket = $this->work($ticket, $team, 0, 540, 535);

                return $this->markResolved($ticket, 300, 'Cam kırıkları toplandı, alan temizlendi.');
            }],

            'SLA ihlali — geç çözüldü ve kapandı' => [self::TECH, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::High, 4320, 'Gondol motorunda aşırı ısınma uyarısı.', ['equipment' => 'Gondol', 'is_operation_stopped' => true]);
                $ticket = $this->work($ticket, $team, 0, 4300, 4280);
                $ticket = $this->markResolved($ticket, 2880, 'Motor fanı değiştirildi; yedek fan tedarikçiden gecikmeli geldi.');

                return $this->confirm($ticket, 2800, 3, null);
            }],

            'Otomatik kapandı — onay verilmedi' => [self::CLEANING, function (Team $team, string $title): Ticket {
                $ticket = $this->open($team, $title, TaskPriorityEnum::Low, 7200, 'Personel giriş kapısı paspası değiştirilmeli.');
                $ticket = $this->work($ticket, $team, 0, 7150, 7140);

                return $this->markResolved($ticket, 5760, 'Paspas yenisiyle değiştirildi.');
            }],
        ];
    }

    // ── Building blocks ─────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $customFields
     */
    private function open(Team $team, string $title, TaskPriorityEnum $priority, int $minutesAgo, string $description, array $customFields = [], ?string $areaName = null): Ticket
    {
        $this->at($this->ago($minutesAgo));
        $team->loadMissing('ticketType');

        $attributes = ['title' => self::MARKER.$title];

        if ($team->ticketType->location_requirement !== LocationRequirementEnum::Hidden && $area = $this->area($areaName ?? ($team->name === self::MALL_ELECTRIC ? 'VIALAND AVM' : 'TEMAPARK'))) {
            $attributes['area_id'] = $area->id;
            $attributes['sub_area_id'] = $area->subAreas->first()?->id;
        }

        return $this->workflow->open(
            $this->requesterFor($team),
            $team,
            $priority,
            $description,
            $this->completeCustomFields($team, $customFields),
            array_filter($attributes),
        );
    }

    /**
     * The supervisor hands the ticket to a member; when the supervisor's
     * account does not resolve to the team's supervisor record, the member
     * takes it from the pool instead.
     */
    private function take(Ticket $ticket, Team $team, int $memberIndex, int $minutesAgo): Ticket
    {
        $this->at($this->ago($minutesAgo));
        $member = $this->membersOf($team)->get($memberIndex) ?? $this->membersOf($team)->first();
        $supervisor = $this->supervisorOf($team);

        if ($supervisor && $this->workflow->isSupervisor($ticket, $supervisor)) {
            return $this->workflow->assign($ticket, $member->employee, $supervisor);
        }

        return $this->workflow->selfAssign($ticket, $member);
    }

    private function work(Ticket $ticket, Team $team, int $memberIndex, int $assignedMinutesAgo, int $startedMinutesAgo): Ticket
    {
        $ticket = $this->take($ticket, $team, $memberIndex, $assignedMinutesAgo);

        $this->at($this->ago($startedMinutesAgo));

        return $this->workflow->startWork($ticket, $this->assigneeOf($ticket));
    }

    private function markResolved(Ticket $ticket, int $minutesAgo, string $note): Ticket
    {
        $this->at($this->ago($minutesAgo));

        return $this->workflow->resolve($ticket, $this->assigneeOf($ticket), $note);
    }

    private function confirm(Ticket $ticket, int $minutesAgo, int $score, ?string $comment): Ticket
    {
        $this->at($this->ago($minutesAgo));

        return $this->workflow->confirm($ticket, $this->requesterOf($ticket), $score, $comment);
    }

    private function comment(Ticket $ticket, User $by, string $note, int $minutesAgo): void
    {
        $this->at($this->ago($minutesAgo));
        $this->tickets->addComment($ticket->fresh(), $by, $note);
    }

    // ── People ──────────────────────────────────────────────────────────

    /**
     * Rotates through people outside the team so the list shows several
     * requesters.
     */
    private function requesterFor(Team $team): User
    {
        $insiders = $this->membersOf($team)->pluck('id')
            ->push($this->supervisorOf($team)?->id)
            ->filter()
            ->all();

        $preferred = User::query()
            ->whereIn('username', self::REQUESTER_USERNAMES)
            ->whereIn('id', $this->demoTeamPeople()->pluck('id'))
            ->whereNotIn('id', $insiders)
            ->get()
            ->sortBy(fn (User $user): int => array_search($user->username, self::REQUESTER_USERNAMES, true))
            ->values();

        $pool = $preferred->isNotEmpty()
            ? $preferred
            : $this->demoTeamPeople()->reject(fn (User $user): bool => in_array($user->id, $insiders, true))->values();

        return $pool->get($this->requesterTurn++ % $pool->count());
    }

    /**
     * Everyone in the demo teams — the only people the seeder may involve.
     *
     * @return Collection<int, User>
     */
    private function demoTeamPeople(): Collection
    {
        return Team::query()
            ->whereIn('name', [self::TECH, self::IT, self::ELECTRIC, self::CLEANING, self::MALL_ELECTRIC])
            ->get()
            ->flatMap(fn (Team $team): Collection => $this->membersOf($team)->push($this->supervisorOf($team)))
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values();
    }

    private function supervisorOf(Team $team): ?User
    {
        return $team->loadMissing('supervisor.user')->supervisor?->user;
    }

    /**
     * Members whose account leads back to the member record.
     *
     * @return Collection<int, User>
     */
    private function membersOf(Team $team): Collection
    {
        return $team->members()->with('user')->orderBy('employees.id')->get()
            ->map(fn ($employee): ?User => $employee->user)
            ->filter(fn (?User $user): bool => $user !== null && $user->employee !== null && $this->workflow->canWorkInTeam($team, $user->employee))
            ->values();
    }

    private function requesterOf(Ticket $ticket): User
    {
        return User::findOrFail($ticket->created_by);
    }

    private function assigneeOf(Ticket $ticket): User
    {
        return $ticket->fresh()->employee->user;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function cycleOf(Ticket $ticket): TicketSlaCycle
    {
        return TicketSlaCycle::query()->where('ticket_id', $ticket->id)->orderByDesc('cycle_no')->firstOrFail();
    }

    private function area(string $name): ?Area
    {
        return Area::with('subAreas')->where('name', $name)->first()
            ?? Area::with('subAreas')->has('subAreas')->orderBy('id')->first();
    }

    /**
     * Fills required fields the scenario did not set, so a type edited in
     * the panel never breaks the seeder.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function completeCustomFields(Team $team, array $values): array
    {
        $fields = collect($team->ticketType->form_fields ?? []);
        $allowed = $fields->pluck('key')->all();

        foreach ($fields as $field) {
            if (! ($field['required'] ?? false) || filled($values[$field['key']] ?? null)) {
                continue;
            }

            $values[$field['key']] = match (FormFieldTypeEnum::tryFrom($field['type'] ?? '')) {
                FormFieldTypeEnum::Select => $field['options'][0] ?? 'Diğer',
                FormFieldTypeEnum::Number => 1,
                FormFieldTypeEnum::Date => $this->now->toDateString(),
                FormFieldTypeEnum::Checkbox => false,
                default => 'Demo',
            };
        }

        return array_intersect_key($values, array_flip($allowed));
    }

    private function ago(int $minutes): CarbonImmutable
    {
        return $this->now->subMinutes($minutes);
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    /**
     * Soft-deletes earlier demo tickets after dropping their SLA history, so
     * reports only count the current set.
     */
    private function removePreviousDemo(): int
    {
        $ids = Ticket::query()->where('title', 'like', self::MARKER.'%')->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        SlaExemption::query()->whereIn('ticket_id', $ids)->delete();
        TicketSlaCycle::query()->whereIn('ticket_id', $ids)->delete();
        TicketAssignment::query()->whereIn('ticket_id', $ids)->delete();
        TicketStatusHistory::query()->whereIn('ticket_id', $ids)->delete();
        Ticket::query()->whereIn('id', $ids)->get()->each->delete();

        return $ids->count();
    }
}
