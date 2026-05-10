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

## Observer Gotcha: `sla_deadline` is always recalculated on create

`TicketObserver::creating()` calls `SlaService::resolvePolicy()` and overwrites
`sla_deadline` with `calculateDeadline($policy, now())` whenever a matching policy
exists. Any `sla_deadline` value passed to the factory is silently replaced.

**Consequence for tests:** if your `setUp()` creates an `SlaPolicy` that covers
the ticket's `(area_id, unit_id, priority)`, you cannot control the initial
`sla_deadline` via the factory. Instead, read it back from the DB after creation:

```php
// BAD — observer overwrites '2026-06-01 14:00:00' if a policy exists
$ticket = Ticket::factory()->create(['sla_deadline' => '2026-06-01 14:00:00']);
$this->assertEquals('2026-06-01 14:00:00', $ticket->sla_deadline->toDateTimeString()); // fails

// GOOD — capture what the observer actually set, then assert it is unchanged
$deadline = $ticket->fresh()->sla_deadline->copy();
// ... exercise the code under test ...
$this->assertEquals($deadline->toDateTimeString(), $ticket->fresh()->sla_deadline->toDateTimeString());
```

The corollary: to test a specific pre-set deadline (e.g. a past deadline for a
breach scenario), either don't create an SLA policy for that ticket's combination,
or freeze time with `Carbon::setTestNow()` so `now() + policy.deadline_minutes`
equals the value you need.

## Reassign Coverage (`tests/Feature/TicketReassignTest.php`)
Three scenarios, each asserting the contract of `TicketService::reassign()`:

- **`IN_PROGRESS → reassign`**: status resets to `ASSIGNED`, `sla_deadline` rebased
  to `now() + policy.deadline_minutes`, `sla_breached` cleared, a status-reset
  history row (`IN_PROGRESS → ASSIGNED`, `note = null`) is written in addition to
  the `__reassign__` employee-change log — 2 new rows total.
- **`ON_HOLD → reassign`**: same as IN_PROGRESS plus `on_hold_since` is nulled.
  Clearing `on_hold_since` is required so `getRemainingMinutes()` and
  `getElapsedPercentage()` don't compute against a stale pause timestamp for the
  new assignee.
- **`ASSIGNED → reassign`**: status stays ASSIGNED; `assigned_at` is updated to
  `now()`, `sla_deadline` is recalculated from `now() + policy.deadline_minutes`,
  `sla_breached` cleared. Only the `__reassign__` employee-change log row is written
  (1 new row — no status-transition row since from == to).

**Not covered here** (covered by `NotificationTest`): the `OPEN → reassign` path,
which goes through the unified post-reassign block (OPEN → ASSIGNED transition row +
reassign log = 2 rows) and is exercised by
`test_reassignment_notifies_new_assignee_and_creator`.

## Assignee Exclusion Coverage (`tests/Feature/TicketActionVisibilityTest.php`)

The `->when($ticket->employee_id / $get('employee_id'), fn ($q, $v) => $q->where('id', '!=', $v))`
filter is added to three query sites so the current assignee cannot be re-selected:

- **ViewTicket group-member branch**: `test_view_ticket_assign_group_branch_excludes_current_assignee`
- **ViewTicket company-fallback branch**: `test_view_ticket_assign_company_fallback_excludes_current_assignee`
- **TicketResource form Select**: `test_ticket_resource_form_employee_options_excludes_selected_employee`

All three are query-level tests. Filament 3.x provides no stable public API for reading
Select options inside a mounted action form, so each test reproduces the exact query the
options closure uses and asserts: current assignee absent, a different eligible member present.

## `getOptionLabelUsing` Coverage (`tests/Feature/TicketActionVisibilityTest.php`)

The `employee_id` Select in `TicketResource` uses `->getOptionLabelUsing()` to resolve
a stored ID to the employee name independently of `->options()`. Without it, Filament
displays the raw integer when the options list doesn't contain the current value — which
happens when `group_id` is not yet reactive on load, or when the self-exclusion filter
has removed the current assignee from the list.

Two closure-level tests (Filament 3.x has no stable API for reading a hydrated field
label in test context):

- **`test_employee_label_resolver_returns_name_for_known_id`** — known ID → employee name
- **`test_employee_label_resolver_falls_back_to_id_string_for_unknown_id`** — unknown ID → ID cast to string

## Creator/Assignee Bypass in `Ticket::scopeVisibleBy`

Within the `ticket.view.group` branch, the scope always ORs in tickets where `created_by = $user->id` OR `employee_id = $employee->id`. This ensures creators and current assignees never lose visibility to their own tickets when the company/group gate would otherwise exclude them.

**Tests are covered by `TicketGroupMembershipScopingTest`** (positive and negative cases), which exercises the `ticket.view.own` branch end-to-end through `TicketResource::getEloquentQuery()`. No separate creator/assignee bypass test class exists — the bypass is validated indirectly via the group-membership tests.

**If you add a test:** create a ticket owned by user A, assign it to user B, then verify both users see it even when neither belongs to the ticket's area/company. Use `ticket.view.group` permission (not `view.all`) to keep the scope branch active.

## Group-Membership Area Scoping Coverage (`tests/Feature/TicketGroupMembershipScopingTest.php`)

Users whose employee belongs to a group in a foreign company's area must see that
area's tickets and groups — even though their `scopedCompanyIds()` only returns their
own company. The logic lives entirely in `Ticket::scopeVisibleBy()` (not in
`TicketResource::getEloquentQuery()`, which is now a single-line passthrough to the
scope).

**How the scope works after the refactor:**

- **`ticket.view.group` branch**: builds `$areaIds` from `GroupMember` → `Group`,
  then applies `AND (area.company_id IN $companyIds OR area_id IN $areaIds)`. The OR
  ensures foreign-company areas reachable via group membership are not filtered out by
  the company restriction.
- **`ticket.view.own` branch**: applies the same company+groupArea OR filter on top of
  the own/assigned WHERE, so widgets and other callers of `scopeVisibleBy()` get
  correct scoping without any extra WHERE in the resource.

**Tests — three locations each with a positive (membership present) and negative (no
membership) case:**

- **Ticket list** (`getEloquentQuery()`): calls `TicketResource::getEloquentQuery()`
  directly after `actingAs()`. Since `getEloquentQuery()` delegates to `scopeVisibleBy()`,
  this exercises the scope's `ticket.view.own` branch end-to-end.
- **`group_id` Select options**: reproduces the closure query directly. Verifies that
  `orWhereIn('id', $memberGroupIds)` includes foreign-company groups the user is a
  member of, and excludes groups with no membership.
- **`area_id` list filter options**: reproduces the closure query directly. Verifies that
  `orWhereIn('id', $groupAreaIds)` includes both own-company areas and foreign
  group-membership areas, and excludes foreign areas without membership.

**setUp note**: Company (and other models) have `created_by` boot hooks. This class
calls `actingAs(User::factory()->create())` in `setUp()` as a placeholder so company
factories at the top of each test don't fail with NOT NULL. Each test then calls
`actingAs($user)` again to switch to the actual scoped user.

**Permission note**: tests use `ticket.view.own` (NOT `ticket.view.all`). `view.all`
makes `scopedCompanyIds()` return `[]` — bypassing the company filter entirely and
making the group-membership OR branch untestable.

## PerformanceService: `resolved_at` and `sla_breached` (not `closed_at`)

`PerformanceService::aggregate()`, `getOverview()` priority breakdown, and
`getRegionBreakdown()` all define "closed" as `resolved_at !== null` and
"on-time" as `!sla_breached`:

```php
$closed = $tickets->filter(fn ($t) => $t->resolved_at !== null);
$onTime = $closed->filter(fn ($t) => !$t->sla_breached);
```

**Why `resolved_at`:** RESOLVED tickets (the dominant terminal state) set
`resolved_at` but leave `closed_at` null until a supervisor explicitly closes
them. Using `closed_at` excluded all RESOLVED tickets from compliance and
resolution-time metrics.

**Why `!sla_breached`:** the inline `closed_at <= sla_deadline` comparison
ignored on-hold pause credits baked into `sla_breached` at write time, and
used the wrong timestamp. `sla_breached` is the single authoritative source.

**`avg_resolution_minutes`** measures `assigned_at → resolved_at` (not `closed_at`).

**Test fixture rule:** when creating a CLOSED ticket in tests, always set
`resolved_at` alongside `closed_at` — in production the lifecycle always
goes through RESOLVED first, so CLOSED tickets always carry both timestamps.
A CLOSED ticket with no `resolved_at` is never counted in `$closed` and will
silently produce wrong compliance numbers in tests.

```php
// CORRECT — matches production lifecycle
Ticket::factory()->create([
    'status'      => TaskStatusEnum::CLOSED,
    'resolved_at' => now(),
    'closed_at'   => now(),
    'sla_breached' => false,
]);
```

## SLA Compliance Window: `resolved_at` not `closed_at`

`TicketStatsOverview` and `SlaComplianceTrendChart` both window on `resolved_at`:

```php
->whereNotNull('resolved_at')->where('resolved_at', '>=', $thirtyDaysAgo)
```

**Why:** tickets are completed at resolution, not at administrative close. Using
`closed_at` excluded RESOLVED tickets from the window entirely — a ticket can sit in
RESOLVED state indefinitely before a supervisor closes it. `resolved_at` is set by
`TicketService::transition` on every path that reaches RESOLVED, so it is always
present for resolved tickets and is also preserved through CLOSED (CLOSED always has a
prior `resolved_at`).

**If you add widget tests:** assert against `resolved_at`-windowed queries, not
`closed_at`. The `sla_breached` column (boolean) is still the correct on-time
signal — do not use `resolved_at <= sla_deadline` inline, as it ignores on-hold
pause credits baked into `sla_breached` at write time.

## Turkish Percent Format: `%66,7` not `66.7%`

All compliance and rate percentages in the UI use the Turkish convention:
percent sign **before** the number, comma as the decimal separator.

```php
'%' . number_format($value, 1, ',', '.')   // → "%66,7"
```

Eleven sites follow this rule:
- `TicketStatsOverview` — SLA compliance stat label
- `PerformanceDashboard` blade — Uyum summary card, Yeniden Açılma summary card,
  priority breakdown bar label, region compliance pill, per-person compliance pill
- `PerformanceDashboard::exportCsv()` — Uyum column in the CSV download
- `EmployeeResource` list — `performance_score` badge, `current_threshold` column
- `ViewEmployee` infolist — SLA Başarı Oranı, SLA Hedef Oranı, unit section `percentage`, priority section `percentage`

**CSS `width:X%` layout values are never formatted this way** — those are CSS
and must remain plain integers followed by `%`.

**If you add display tests for percentage values**, assert the `%X,Y` form:
```php
$this->assertStringContainsString('%66,7', $renderedOutput);
// NOT: '66.7%' or '66,7%'
```

## Employee: `resolved_at` and `sla_breached` (not `closed_at`)

`Employee::refreshPerformanceMetrics()` and `Employee::getUnitPerformanceStats()` define
the "on-time" signal as `sla_breached = 0 AND resolved_at IS NOT NULL` — not `closed_at`:

```sql
SUM(CASE WHEN sla_breached = 0 AND resolved_at IS NOT NULL THEN 1 ELSE 0 END) as success_count
```

`ViewEmployee` applies the same rule in three places:

1. **Performance cohort** (section 3 denominator):
   ```php
   ->whereNotNull('resolved_at')->where('sla_breached', false)
   // NOT: ->whereNotNull('closed_at')->whereColumn('closed_at', '<=', 'sla_deadline')
   ```

2. **Unit section `selectRaw`** (section 4):
   ```sql
   SUM(CASE WHEN resolved_at IS NOT NULL AND sla_breached = 0 THEN 1 ELSE 0 END) as on_time_count
   ```

3. **Priority section `selectRaw`** (section 5) — same expression grouped by `priority`.

**`raw_percentage` key in RepeatableEntry data:** both the unit and priority sections store
a numeric `raw_percentage` key alongside the formatted `percentage` string. Badge color
closures use `$record['raw_percentage']` — not `floatval($state)` — because after the
Turkish format fix `floatval('%66,7')` returns `0.0`:

```php
->color(fn ($state, $record) =>
    ($record['raw_percentage'] ?? 0) >= 80 ? 'success' : (($record['raw_percentage'] ?? 0) >= 50 ? 'warning' : 'danger')
),
```

**`SlaPoliciesRelationManager` removed:** the employee↔SLA pivot is managed through
`CompanySetupWizard`. The relation manager tab was deleted from `EmployeeResource`
to prevent bypassing the wizard's guards via `AttachAction`.

## `markClosed()`: `sla_breached` uses `resolved_at`, not `now()`

`TicketService::markClosed()` evaluates the breach outcome against `resolved_at ?? now()`,
not bare `now()`:

```php
$finalAt = $ticket->resolved_at ?? now();
$ticket->sla_breached = $ticket->sla_deadline && $finalAt->isAfter($ticket->sla_deadline);
```

**Why:** RESOLVED → CLOSED is a supervisor administrative action that typically happens hours
or days after the technician resolved the ticket. Using `now()` as the reference timestamp
meant any ticket resolved before the deadline was flipped to `sla_breached = true` at close
time, corrupting all historical compliance metrics for the RESOLVED → CLOSED path.

**Corollary for tests:** when testing the RESOLVED → CLOSED transition, assert that
`sla_breached` reflects the moment of resolution, not the moment of closure. The test fixture
in `TicketReopenTest` (CLOSED → ASSIGNED) creates CLOSED tickets with `resolved_at` set —
that fixture is correct; the breach column was evaluated at resolve time.

## `getSlaStatusAttribute()` removed — use `getSlaStatusLabel()`

`Ticket::getSlaStatusAttribute()` (which returned legacy strings `'SUCCESS'`/`'FAILED'`/
`'SLA_BREACHED'`/`'IN_PROGRESS'`) has been deleted. It was dead code: nothing in the
codebase read `->sla_status`. It also had the `closed_at` bug — RESOLVED tickets
(`closed_at = null`) would be misclassified as active.

The Reform-era replacement is `Ticket::getSlaStatusLabel(): string`, which returns
human-readable Turkish strings and correctly handles all lifecycle states including
RESOLVED. Never re-introduce `getSlaStatusAttribute()` or `->sla_status`.

## `ticket.view.all` permission: check both old and new name

`Ticket::scopeVisibleBy()` explicitly checks both `ticket.view.all` (Reform-era) and
`view_all_tasks` (legacy) for backward compatibility. Any code that hand-rolls its own
permission check for "can this user see all tickets?" must also check both:

```php
$user->can('view_all_tasks') || $user->can('ticket.view.all')
```

**Sites fixed:**
- `UnitResource/RelationManagers/TicketsRelationManager::applyTaskPermissionFilter()`
- `TaskExporter` — `$canViewAllTasks` gate for the `createdBy` export column

If you add a new relation manager or custom query that gates on ticket-view-all access,
always use the dual check above, not just one name.

## Reopen Coverage (`tests/Feature/TicketReopenTest.php`)
- Two scenarios covered: `RESOLVED → ASSIGNED` and `CLOSED → ASSIGNED`. Both assert the same 7 invariants (status, `assigned_at` re-stamp, deadline rebase, `sla_breached=false`, cleared timestamps, history row, `TicketStatusChangedNotification` to assignee). The CLOSED variant additionally checks `closed_at` and `closed_by` are nulled.
- **`CANCELLED → ASSIGNED` is intentionally NOT tested.** The transition matrix keeps `cancelled → []` (terminal — no path back); the source set in `TicketService::transition`'s reopen branch still includes `CANCELLED` as defensive code in case the matrix opens that arm later, but until it does the path is unreachable from any caller. Add a test only after the matrix is opened — otherwise the test would have to fabricate an invalid transition to exercise dead code.
