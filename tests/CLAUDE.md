# Tests Guide

## Test Database
Tests use **SQLite in-memory** (configured via `phpunit.xml`).
- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`
- Production uses MySQL; SQLite is test-only

## Rule
Feature test required for every business rule. Use `RefreshDatabase` on all feature tests.

## Authentication in Tests
Most models' `booted()` hooks call `auth()->id()` for `created_by`. To avoid NULL errors:
```php
protected function setUp(): void
{
    parent::setUp();
    $this->actingAs(User::factory()->create());
}
```

## Test Location
All business-rule tests in `tests/Feature/`

## Required Factories
- `UserFactory` (exists: `database/factories/UserFactory.php`)
- `TicketFactory` — create if missing
- `GroupFactory` — create if missing
- `SlaPolicyFactory` — create if missing

## Filament Resource Testing Pattern
```php
use Livewire\Livewire;
use App\Filament\Resources\TicketResource;

Livewire::test(TicketResource\Pages\ListTickets::class)
    ->assertCanSeeTableRecords([$ticket]);
```

## HTTP Testing for Authorization
```php
$this->actingAs($technician)
     ->get(route('filament.dashboard.resources.tickets.index'))
     ->assertForbidden();
```

## SLA Tests
Fix time with `Carbon::setTestNow()` for deterministic deadline tests:
```php
Carbon::setTestNow('2026-01-01 08:00:00');
$ticket = Ticket::factory()->create([...]);
$this->assertTrue($slaService->checkBreach($ticket));
Carbon::setTestNow(); // reset
```

## Permission Tests Structure
- `test_technician_cannot_view_another_technicians_ticket`
- `test_supervisor_can_view_region_tickets`
- `test_default_role_gets_403_on_ticket_routes`
- `test_sla_deadline_calculated_on_ticket_create`
- `test_sla_breach_detected_when_deadline_passed`

## HTTP Authorization Quirk (Filament 3.x)
Filament returns **404, not 403**, on model-bound routes (view/edit) when
the model's `query()` scope filters the record out for the user. Tests
that hit those routes should accept `[403, 404]` as both indicate denial.
Index/create/page routes do return 403 cleanly.
