<?php

namespace Database\Seeders;

use App\Enums\ActiveStatusEnum;
use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\TaskPriorityEnum;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WorkCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo set-up of the Phase 1 request model for the test environment:
 * ticket types, teams (cross-company), team SLAs, calendars and the
 * fixed-date national holidays. Idempotent — matched by name / date.
 *
 * Team staff is taken from employees linked to these usernames when they
 * exist, otherwise from any active employees, so the seeder also runs on
 * an empty database.
 *
 * php artisan db:seed --class=DemoRequestModelSeeder
 */
class DemoRequestModelSeeder extends Seeder
{
    /**
     * Team name => [type name, supervisor username, member usernames, calendar name].
     *
     * @var array<string, array{0: string, 1: string, 2: array<int, string>, 3: string|null}>
     */
    private const TEAMS = [
        'Teknik Servis Ekibi' => ['Arıza', 'brahimmahamoud', ['furkancatal', 'hakan.aydin', 'emirhankaraca'], '7/24'],
        'IT Destek Ekibi' => ['IT Destek', 'onurbozkurt', ['fatihsoyler', 'serkanfidanci', 'anilhekimoglu'], 'Hafta içi 08:30-18:00'],
        'Elektrik Ekibi' => ['Elektrik', 'burakakin', ['sametkose', 'yunusbel'], 'Hafta içi + Cumartesi yarım gün'],
        'Temizlik Ekibi' => ['Temizlik', 'hakan.aydin', ['sametkose', 'furkancatal'], '7/24'],
    ];

    /**
     * Fixed-date national holidays. Religious holidays move every year and
     * must be entered from the official calendar.
     *
     * @var array<string, string>
     */
    private const HOLIDAYS = [
        '2027-01-01' => 'Yılbaşı',
        '2027-04-23' => 'Ulusal Egemenlik ve Çocuk Bayramı',
        '2027-05-01' => 'Emek ve Dayanışma Günü',
        '2027-05-19' => 'Atatürk\'ü Anma, Gençlik ve Spor Bayramı',
        '2027-07-15' => 'Demokrasi ve Millî Birlik Günü',
        '2027-08-30' => 'Zafer Bayramı',
        '2027-10-29' => 'Cumhuriyet Bayramı',
    ];

    public function run(): void
    {
        $calendars = $this->seedCalendars();
        $types = $this->seedTypes();

        foreach (self::HOLIDAYS as $date => $name) {
            Holiday::updateOrCreate(['date' => $date], ['name' => $name]);
        }

        $fallback = Employee::query()
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach (self::TEAMS as $teamName => [$typeName, $supervisorUsername, $memberUsernames, $calendarName]) {
            $supervisor = $this->employeeFor($supervisorUsername) ?? $fallback->shift();

            if (! $supervisor) {
                $this->command?->warn("No employee available for {$teamName}; skipped.");

                continue;
            }

            $team = Team::updateOrCreate(
                ['name' => $teamName],
                [
                    'ticket_type_id' => $types[$typeName]->id,
                    'supervisor_employee_id' => $supervisor->id,
                    'work_calendar_id' => $calendarName === '7/24' ? null : $calendars[$calendarName]->id,
                    'allow_self_assign' => true,
                    'is_active' => true,
                ],
            );

            $members = $this->membersFor($memberUsernames, $fallback);
            $team->members()->sync($members->pluck('id'));

            foreach (TaskPriorityEnum::cases() as $priority) {
                [$response, $resolution] = Team::DEFAULT_SLA_MINUTES[$priority->value];

                $team->slaPolicies()->updateOrCreate(
                    ['priority' => $priority->value],
                    ['response_minutes' => $response, 'resolution_minutes' => $resolution, 'success_threshold' => 80],
                );
            }
        }

        $this->command?->info('Demo request model ready: '.count($types).' types, '.Team::count().' teams.');
    }

    /**
     * @return array<string, WorkCalendar>
     */
    private function seedCalendars(): array
    {
        $weekdays = fn (string $start, string $end): array => collect(range(1, 5))
            ->mapWithKeys(fn (int $day): array => [(string) $day => ['start' => $start, 'end' => $end]])
            ->all();

        return [
            'Hafta içi 08:30-18:00' => WorkCalendar::updateOrCreate(
                ['name' => 'Hafta içi 08:30-18:00'],
                ['is_24_7' => false, 'working_hours' => $weekdays('08:30', '18:00'), 'observes_holidays' => true, 'is_active' => true],
            ),
            'Hafta içi + Cumartesi yarım gün' => WorkCalendar::updateOrCreate(
                ['name' => 'Hafta içi + Cumartesi yarım gün'],
                [
                    'is_24_7' => false,
                    'working_hours' => $weekdays('08:00', '18:00') + ['6' => ['start' => '08:00', 'end' => '13:00']],
                    'observes_holidays' => true,
                    'is_active' => true,
                ],
            ),
            '7/24 Kesintisiz' => WorkCalendar::updateOrCreate(
                ['name' => '7/24 Kesintisiz'],
                ['is_24_7' => true, 'working_hours' => null, 'observes_holidays' => false, 'is_active' => true],
            ),
        ];
    }

    /**
     * @return array<string, TicketType>
     */
    private function seedTypes(): array
    {
        $definitions = [
            'Arıza' => [
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'danger',
                'location_requirement' => LocationRequirementEnum::Required,
                'description' => 'Ünite, ekipman ve tesislerdeki arızalar.',
                'form_fields' => [
                    ['key' => 'equipment', 'label' => 'Arızalı Ekipman / Ünite', 'type' => FormFieldTypeEnum::Text->value, 'required' => true, 'options' => [], 'help' => null],
                    ['key' => 'is_operation_stopped', 'label' => 'Operasyon durdu mu?', 'type' => FormFieldTypeEnum::Checkbox->value, 'required' => false, 'options' => [], 'help' => null],
                ],
            ],
            'IT Destek' => [
                'icon' => 'heroicon-o-computer-desktop',
                'color' => 'info',
                'location_requirement' => LocationRequirementEnum::Optional,
                'description' => 'Bilgisayar, yazıcı, ağ ve yazılım talepleri.',
                'form_fields' => [
                    ['key' => 'device_kind', 'label' => 'Konu', 'type' => FormFieldTypeEnum::Select->value, 'required' => true, 'options' => ['Bilgisayar', 'Yazıcı', 'Telefon', 'Ağ / İnternet', 'Yazılım / Hesap'], 'help' => null],
                    ['key' => 'asset_no', 'label' => 'Demirbaş No', 'type' => FormFieldTypeEnum::Text->value, 'required' => false, 'options' => [], 'help' => 'Cihazın üzerindeki etiket numarası'],
                ],
            ],
            'Elektrik' => [
                'icon' => 'heroicon-o-bolt',
                'color' => 'warning',
                'location_requirement' => LocationRequirementEnum::Required,
                'description' => 'Aydınlatma, priz, pano ve enerji kesintileri.',
                'form_fields' => [
                    ['key' => 'panel_no', 'label' => 'Pano No', 'type' => FormFieldTypeEnum::Text->value, 'required' => false, 'options' => [], 'help' => null],
                ],
            ],
            'Temizlik' => [
                'icon' => 'heroicon-o-sparkles',
                'color' => 'success',
                'location_requirement' => LocationRequirementEnum::Required,
                'description' => 'Alan temizliği ve hijyen talepleri.',
                'form_fields' => [],
            ],
        ];

        $types = [];
        $order = 0;

        foreach ($definitions as $name => $attributes) {
            $types[$name] = TicketType::updateOrCreate(
                ['name' => $name],
                $attributes + ['is_active' => true, 'sort_order' => $order += 10],
            );
        }

        return $types;
    }

    private function employeeFor(string $username): ?Employee
    {
        return User::query()->where('username', $username)->first()?->employee;
    }

    /**
     * @param  array<int, string>  $usernames
     * @param  Collection<int, Employee>  $fallback
     * @return Collection<int, Employee>
     */
    private function membersFor(array $usernames, Collection $fallback): Collection
    {
        $members = collect($usernames)
            ->map(fn (string $username): ?Employee => $this->employeeFor($username))
            ->filter();

        return $members->isNotEmpty() ? $members : $fallback->take(2);
    }
}
