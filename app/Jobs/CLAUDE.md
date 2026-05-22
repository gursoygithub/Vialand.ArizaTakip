# Jobs Guide

## Queue
- Driver: `database` (`QUEUE_CONNECTION=database` in `.env`)
- All async work via `Job::dispatch()` — never run in `sync` for production
- Schedule entries live in `bootstrap/app.php` → `withSchedule()` callback (Laravel 12 — no `Kernel.php`)

## CheckSlaBreaches
- Path: `App\Jobs\CheckSlaBreaches` — implements `ShouldQueue`
- Schedule: every 5 minutes (configured in `bootstrap/app.php`)
- One of three writers of `tickets.sla_breached`; the other two are `TicketObserver::saving` and `TicketService` terminal-state markers (`markResolved` / `markClosed` / `markCancelled`)

### What it does
- **Sweep loop** — finds active-status tickets with `sla_deadline < now()` and `sla_breached = false`, flips the column, dispatches `TicketSlaBreached` event, fans out `SlaBreachedNotification` to assignee + creator (mail + panel + FCM)
- **Warning loop** — finds active-status tickets with `sla_deadline > now()` and `sla_breached = false` whose elapsed-time fraction has crossed 80%, fans out `SlaWarningNotification` to assignee + creator (mail + panel + FCM)
- Eligible statuses: `OPEN`, `ASSIGNED`, `IN_PROGRESS`, `PENDING` (skips `ON_HOLD` because the clock is paused, and the terminal trio because their breach state is set deterministically by `TicketService`)
- Both loops `chunkById(100)` to bound memory on large backlogs
- Both notification classes (`SlaWarningNotification`, `SlaBreachedNotification`) always send mail regardless of `mail_enabled` config

### De-dupe
- `alreadySentToday(Ticket, NotificationClass)` reads today's `notifications` rows and inspects `data.actions[*].url` for `/tickets/{id}` — portable across MySQL/SQLite (no JSON-extract SQL)
- Survives the Observer flipping `sla_breached` mid-window — the database/push channels won't double-send even if the job picks up a row twice

### Recipients
- `slaRecipients()` returns assignee (`$ticket->employee->user`) + creator (`User::find($ticket->created_by)`) + **group supervisor** (when `employee_id` is null but `group_id` is set — ensures the responsible supervisor is alerted before individual assignment)
- No admin fallback by design; admins see breach state through the panel widgets and the navigation badge
- Missing relations short-circuit silently (filter + unique('id') on the collect)

## Adding New Jobs
- Place the class under `app/Jobs/`
- For periodic jobs, register the schedule in `bootstrap/app.php` and document the cadence in this file
- For ticket-touching jobs, prefer dispatching from `TicketService` rather than the Observer to keep "observers trigger services, services contain logic"
