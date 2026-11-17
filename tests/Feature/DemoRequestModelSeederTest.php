<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WorkCalendar;
use Database\Seeders\DemoRequestModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRequestModelSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_builds_the_demo_set_up_and_can_run_twice(): void
    {
        $this->actingAs(User::factory()->create());
        Employee::factory()->count(12)->create(['company_id' => Company::factory()]);

        $this->seed(DemoRequestModelSeeder::class);
        $this->seed(DemoRequestModelSeeder::class);

        $this->assertSame(4, TicketType::count());
        $this->assertSame(4, Team::count());
        $this->assertSame(3, WorkCalendar::count());
        $this->assertSame(7, Holiday::count());

        Team::with('slaPolicies', 'members')->get()->each(function (Team $team): void {
            $this->assertCount(4, $team->slaPolicies, $team->name);
            $this->assertNotEmpty($team->members, $team->name);
        });

        $it = TicketType::where('name', 'IT Destek')->sole();
        $this->assertSame('device_kind', $it->form_fields[0]['key']);
        $this->assertTrue(Team::where('name', 'Teknik Servis Ekibi')->sole()->runsAroundTheClock());
        $this->assertFalse(Team::where('name', 'IT Destek Ekibi')->sole()->runsAroundTheClock());
    }
}
