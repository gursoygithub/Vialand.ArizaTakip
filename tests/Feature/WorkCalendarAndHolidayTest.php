<?php

namespace Tests\Feature;

use App\Filament\Resources\HolidayResource\Pages\ManageHolidays;
use App\Filament\Resources\WorkCalendarResource;
use App\Filament\Resources\WorkCalendarResource\Pages\ManageWorkCalendars;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\WorkCalendar;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\GrantsShieldPermissions;
use Tests\TestCase;

class WorkCalendarAndHolidayTest extends TestCase
{
    use GrantsShieldPermissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->userWithResourcePermissions(['work::calendar', 'holiday']));
    }

    public function test_admin_creates_an_office_hours_calendar(): void
    {
        Livewire::test(ManageWorkCalendars::class)
            ->callAction('create', [
                'name' => 'Hafta içi + Cumartesi yarım gün',
                'is_24_7' => false,
                'observes_holidays' => true,
                'working_hours' => [
                    '1' => ['start' => '08:00', 'end' => '18:00'],
                    '2' => ['start' => '08:00', 'end' => '18:00'],
                    '3' => ['start' => '08:00', 'end' => '18:00'],
                    '4' => ['start' => '08:00', 'end' => '18:00'],
                    '5' => ['start' => '08:00', 'end' => '18:00'],
                    '6' => ['start' => '09:00', 'end' => '13:00'],
                    '7' => ['start' => null, 'end' => null],
                ],
            ])
            ->assertHasNoActionErrors();

        $calendar = WorkCalendar::where('name', 'Hafta içi + Cumartesi yarım gün')->sole();
        $this->assertSame(['start' => '09:00', 'end' => '13:00'], $calendar->hoursFor(6));
        $this->assertNull($calendar->hoursFor(7));
        $this->assertArrayNotHasKey('7', $calendar->working_hours);
    }

    public function test_closing_time_must_be_after_opening_time(): void
    {
        Livewire::test(ManageWorkCalendars::class)
            ->callAction('create', [
                'name' => 'Hatalı',
                'is_24_7' => false,
                'working_hours' => ['1' => ['start' => '18:00', 'end' => '08:00']],
            ])
            ->assertHasActionErrors(['working_hours.1.end' => 'after']);
    }

    public function test_a_day_needs_both_times_or_none(): void
    {
        Livewire::test(ManageWorkCalendars::class)
            ->callAction('create', [
                'name' => 'Eksik',
                'is_24_7' => false,
                'working_hours' => ['1' => ['start' => '08:00', 'end' => null]],
            ])
            ->assertHasActionErrors(['working_hours.1.end' => 'required_with']);
    }

    public function test_around_the_clock_calendar_stores_no_hours(): void
    {
        $data = WorkCalendarResource::normalize([
            'name' => '7/24',
            'is_24_7' => true,
            'working_hours' => ['1' => ['start' => '08:00', 'end' => '18:00']],
        ]);

        $this->assertNull($data['working_hours']);

        $calendar = WorkCalendar::factory()->aroundTheClock()->create();
        $this->assertSame(['start' => '00:00', 'end' => '24:00'], $calendar->hoursFor(7));
    }

    public function test_summary_lists_open_days(): void
    {
        $calendar = WorkCalendar::factory()->create([
            'working_hours' => [
                '1' => ['start' => '08:00', 'end' => '17:00'],
                '7' => ['start' => '10:00', 'end' => '14:00'],
            ],
        ]);

        $this->assertSame('Pzt 08:00-17:00, Paz 10:00-14:00', WorkCalendarResource::summarize($calendar));
    }

    public function test_calendar_used_by_a_team_cannot_be_deleted(): void
    {
        $used = WorkCalendar::factory()->create();
        Team::factory()->withCalendar($used)->create();
        $unused = WorkCalendar::factory()->create();

        $this->assertFalse(WorkCalendarResource::canDelete($used));
        $this->assertTrue(WorkCalendarResource::canDelete($unused));
    }

    public function test_admin_adds_a_holiday_and_dates_are_unique(): void
    {
        Livewire::test(ManageHolidays::class)
            ->callAction('create', ['date' => '2026-10-29', 'name' => 'Cumhuriyet Bayramı'])
            ->assertHasNoActionErrors();

        $this->assertSame('Cumhuriyet Bayramı', Holiday::whereDate('date', '2026-10-29')->value('name'));

        Livewire::test(ManageHolidays::class)
            ->callAction('create', ['date' => '2026-10-29', 'name' => 'Tekrar'])
            ->assertHasActionErrors(['date' => 'unique']);
    }

    public function test_lifecycle_settings_have_agreed_defaults(): void
    {
        $settings = app(GeneralSettings::class);

        $this->assertSame(3, $settings->auto_close_days);
        $this->assertSame(7, $settings->reopen_window_days);
    }
}
