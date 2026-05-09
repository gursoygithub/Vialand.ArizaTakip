<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Widgets\TicketStatsOverview;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $admin = User::factory()->create(['username' => 'stats-admin']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $area    = Area::factory()->create(['status' => ActiveStatusEnum::ACTIVE]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $unit    = Unit::factory()->create();

        return Ticket::factory()->create(array_merge([
            'area_id'     => $area->id,
            'sub_area_id' => $subArea->id,
            'unit_id'     => $unit->id,
            'priority'    => TaskPriorityEnum::Medium,
        ], $overrides));
    }

    /**
     * A RESOLVED ticket whose resolved_at falls in the current calendar month
     * must be counted in the "Bu Ay Çözülen" stat.
     */
    public function test_resolved_this_month_is_counted(): void
    {
        Carbon::setTestNow('2026-05-15 10:00:00');

        $ticket = $this->makeTicket([
            'status'      => TaskStatusEnum::RESOLVED,
            'resolved_at' => Carbon::now()->startOfMonth()->addDays(5),
        ]);

        $component = Livewire::test(TicketStatsOverview::class);

        // Extract the stats array via getStats reflection
        $widget = new TicketStatsOverview();
        $stats  = $this->invokeGetStats($widget);

        // "Bu Ay Çözülen" is the 4th stat (index 3)
        $resolvedThisMonthStat = $stats[3];
        $this->assertStringContainsString('1', $resolvedThisMonthStat->getValue());

        Carbon::setTestNow();
    }

    /**
     * A RESOLVED ticket whose resolved_at is in a previous month must NOT
     * appear in the "Bu Ay Çözülen" count.
     */
    public function test_resolved_last_month_is_not_counted(): void
    {
        Carbon::setTestNow('2026-05-15 10:00:00');

        // Ticket resolved last month
        $this->makeTicket([
            'status'      => TaskStatusEnum::RESOLVED,
            'resolved_at' => Carbon::now()->subMonth()->endOfMonth(),
        ]);

        $widget = new TicketStatsOverview();
        $stats  = $this->invokeGetStats($widget);

        // "Bu Ay Çözülen" is the 4th stat (index 3) — must be 0
        $resolvedThisMonthStat = $stats[3];
        $this->assertStringContainsString('0', $resolvedThisMonthStat->getValue());

        Carbon::setTestNow();
    }

    /**
     * All stat labels must resolve to actual Turkish strings, not raw key names
     * (i.e. no label starts with "ui.").
     */
    public function test_stat_labels_resolve_to_translated_strings(): void
    {
        $widget = new TicketStatsOverview();
        $stats  = $this->invokeGetStats($widget);

        foreach ($stats as $stat) {
            $label = $stat->getLabel();
            $this->assertStringNotContainsString(
                'ui.',
                (string) $label,
                "Stat label '{$label}' looks like an untranslated i18n key."
            );
        }
    }

    /**
     * A user with the widget_TicketStatsOverview permission can see the widget.
     */
    public function test_user_with_permission_can_view_widget(): void
    {
        $this->assertTrue(TicketStatsOverview::canView());
    }

    /**
     * A user without any relevant permission cannot view the widget.
     */
    public function test_user_without_permission_cannot_view_widget(): void
    {
        $plain = User::factory()->create(['username' => 'plain-user']);
        $plain->assignRole('default');
        $this->actingAs($plain);

        $this->assertFalse(TicketStatsOverview::canView());
    }

    /**
     * Invoke the protected getStats() method via reflection.
     *
     * @return array<\Filament\Widgets\StatsOverviewWidget\Stat>
     */
    private function invokeGetStats(TicketStatsOverview $widget): array
    {
        $method = new \ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);
        return $method->invoke($widget);
    }
}
