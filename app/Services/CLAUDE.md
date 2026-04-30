# Services Guide

## Rule
All business logic lives in `app/Services/`. Never put business logic in Filament Resources,
Widgets, or Observers (observers trigger services, they don't contain logic).

## SlaService (`App\Services\SlaService`)
- `resolvePolicy(int $areaId, ?int $subAreaId, int $unitId, string|int $priority): ?SlaPolicy`
  Lookup order: (area + subArea + unit + priority) → fallback (area + unit + priority)
- `calculateDeadline(SlaPolicy $policy, Carbon $from): Carbon`
  Returns `$from->addMinutes($policy->deadline_minutes)`
- `checkBreach(Ticket $ticket): bool`
  Returns true if `sla_deadline` is not null, is in the past, and ticket is not closed

## TicketService (`App\Services\TicketService`)
- `transition(Ticket $ticket, string $toStatus, User $by, ?string $note = null): Ticket`
  Validates allowed transitions, creates TicketStatusHistory, sets timestamps
- Transition map (from → allowed to):
  `open → [assigned, cancelled]`
  `assigned → [in_progress, on_hold, cancelled]`
  `in_progress → [resolved, on_hold, cancelled]`
  `on_hold → [in_progress, cancelled]`
  `resolved → [closed, in_progress]`

## PerformanceService (`App\Services\PerformanceService`)
- `getStats(User $user, Carbon $from, Carbon $to): array`
  Returns: total, on_time, breach_count, compliance_rate, avg_resolution_minutes
- `getTeamStats(int $areaId, Carbon $from, Carbon $to): Collection`
  Per-person stats for all employees in an area

## Repository Pattern
- Contracts: `App\Repositories\Contracts\TicketRepositoryInterface`
- Implementations: `App\Repositories\TicketRepository`
- Bound in `AppServiceProvider::register()`

## Async / Scheduled Work
- `App\Jobs\CheckSlaBreaches` — queued, runs every 5 min (`bootstrap/app.php`)
  - Marks breached tickets, dispatches `TicketSlaBreached` event
  - Sends `SlaWarningNotification` at 80% time elapsed
  - Sends `SlaBreachedNotification` to supervisors/admins on breach
- `App\Observers\TicketObserver` — registered in AppServiceProvider
  - On creating: SlaService.resolvePolicy() → set sla_deadline
  - On status change: set assigned_at / resolved_at / closed_at + write history
  - On status change: dispatch TicketAssignedNotification / TicketClosedNotification

## Notifications (Filament-compatible)
All ticket notifications return `FilamentNotification::getDatabaseMessage()`
from `toDatabase()` so the panel's bell renders them with title, body, icon,
and an action button. Mail channel is opt-in via `config/notifications.php`
(`mail_enabled`, default false).

## Setup Chain Rules

These rules are NON-NEGOTIABLE. Every feature that touches setup data must
enforce this chain:

1. **Company must exist before anything else**
   → companies populated automatically via `employee:sync`

2. **Area (bölge) requires a company**
   → `Area::company_id` is mandatory

3. **SubArea (lokasyon) requires an area**
   → `SubArea::area_id` is mandatory

3b. **SubArea (lokasyon) — at least one required per area**
   → Areas with 0 sub_areas block ticket creation (no location to select)
   → `CompanySetupWizard` hard-blocks step 2 until all areas have at least
     one sub_area

4. **SLA Policy requires: area + unit + priority** (at minimum)
   → Cannot create SLA without at least one area
   → `SlaService::resolvePolicy()` uses 3-level fallback but always needs
     `area_id` as the starting point

5. **Group requires: area + unit + supervisor (employee_id)**
   → Cannot create group without area, unit, or supervisor

5b. **Every group must have at least one member**
   → A group with zero members can't carry tickets

5c. **`CompanySetupWizard` hard-blocks step 4 on all three conditions**
   → No groups for company / group without supervisor / group with zero
     members all halt step 4 with a danger notification (no soft path)

6. **Ticket requires: area + SLA policy** (resolved automatically)
   → `TicketObserver` resolves SLA on `creating`
   → If no SLA found → ticket gets `NULL sla_deadline` (no SLA tracking)

7. **User visibility requires: employee → company_id**
   → If employee has no `company_id` → fallback to own tickets only

Breaking this chain at any point causes silent failures. Always validate
prerequisites before allowing creation.
