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
