# Tests Guide

## Test Database
Tests use **SQLite in-memory** (configured via `phpunit.xml`).
- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`
- Production uses MySQL; SQLite is test-only

## Running
- 44 feature test files / 326 test methods in `tests/Feature/` (all green on `faz-6-panel-duzeni`, 2026-11-17; `LEGACY_TICKETS_ENABLED=true` in phpunit.xml)
- Needs PHP 8.4 (host CLI is 8.3 → Composer platform check aborts). One-off container, no stack needed:
  `docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/var/www/html -w /var/www/html --entrypoint php sail-8.4/app vendor/bin/phpunit --testsuite Feature`
  (`-u` is required — run as root it leaves root-owned log/view files that break the local app)
- Always pass `--testsuite Feature`: `phpunit.xml` also lists `tests/Unit`, which does not exist, so a plain run errors out
- Filter: append `--filter=TicketReassignTest`
- Helper trait `Tests\Feature\Concerns\GrantsShieldPermissions::userWithResourcePermissions([...])` creates and grants Shield resource permissions
- `define_via_gate = false` → a `super_admin` test user gets 403 on resources whose Shield permission (`view_any_X`, …) was not created. `PermissionSeeder` does not create Shield resource permissions; create/grant them in the test (`Permission::findOrCreate`)

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
All exist in `database/factories/`:
- `UserFactory`, `TicketFactory` (an old-system row: no team), `EmployeeFactory`, `TeamFactory`, `TicketTypeFactory`, `WorkCalendarFactory`
- `AreaFactory`, `CompanyFactory`, `SubAreaFactory`, `HolidayFactory`

## Filament Resource Testing Pattern
```php
use Livewire\Livewire;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;

Livewire::test(ListTeamTickets::class)
    ->assertCanSeeTableRecords([$ticket]);
```

## HTTP Testing for Authorization
```php
$this->actingAs($technician)
     ->get(route('filament.dashboard.resources.tickets.index'))
     ->assertForbidden();
```

## HTTP Authorization Quirk (Filament 3.x)
Filament returns **404, not 403**, on model-bound routes (view/edit) when
the model's `query()` scope filters the record out for the user. Tests
that hit those routes should accept `[403, 404]` as both indicate denial.
Index/create/page routes do return 403 cleanly.

## Turkish Percent Format: `%66,7` not `66.7%`

All compliance and rate percentages in the UI use the Turkish convention:
percent sign **before** the number, comma as the decimal separator.

```php
'%' . number_format($value, 1, ',', '.')   // → "%66,7"
```

Eleven sites follow this rule:
- `TicketStatsOverview` — SLA compliance stat label
- `EmployeeResource` list — `performance_score` badge, `current_threshold` column
- `ViewEmployee` infolist — SLA Başarı Oranı, SLA Hedef Oranı, unit section `percentage`, priority section `percentage`

**CSS `width:X%` layout values are never formatted this way** — those are CSS
and must remain plain integers followed by `%`.

**If you add display tests for percentage values**, assert the `%X,Y` form:
```php
$this->assertStringContainsString('%66,7', $renderedOutput);
// NOT: '66.7%' or '66,7%'
```
