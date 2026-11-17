# Jobs Guide

## Queue
- Driver: `database` (`QUEUE_CONNECTION=database` in `.env`)
- All async work via `Job::dispatch()` — never run in `sync` for production
- Schedule entries live in `bootstrap/app.php` → `withSchedule()` callback (Laravel 12 — no `Kernel.php`)
- In production, supervisor runs `schedule:work` (1×) and `queue:work --tries=3 --max-time=3600` (2×), both as root. Workers pick up new code only when they restart (container restart or `--max-time` expiry)
- Current schedule: `employee:sync` every 5 min, `CheckSlaBreaches` every 5 min (`withoutOverlapping`, queued — not run inline), tomorrow's log file pre-created at 23:55 (`chmod 0777`)

## CheckSlaBreaches
- Path: `App\Jobs\CheckSlaBreaches` — implements `ShouldQueue`
- Schedule: every 5 minutes via `$schedule->job(...)` → pushed onto the `database` queue
- `TicketSlaBreached` event is dispatched but has **no listeners**
- One of three writers of `tickets.sla_breached`; the other two are `TicketObserver::saving` and `TicketService` terminal-state markers (`markResolved` / `markClosed` / `markCancelled`)

### What it does
- **Sweep loop** — finds active-status tickets with `sla_deadline < now()` and `sla_breached = false`, flips the column, dispatches `TicketSlaBreached` event, fans out `SlaBreachedNotification` to assignee + creator (mail + panel + FCM)
- **Warning loop** — finds active-status tickets with `sla_deadline > now()` and `sla_breached = false` whose elapsed-time fraction has crossed 80%, fans out `SlaWarningNotification` to assignee + creator (mail + panel + FCM). The fraction is `(now - created_at) / (sla_deadline - created_at)` — after a reassign/reopen rebases `sla_deadline` from `now()`, an old ticket can cross 80% immediately (Known Issue #4)
- Neither loop checks `ticket_mutes`; FCM in this job is not gated by `wantsNotification`
- Eligible statuses: `OPEN`, `ASSIGNED`, `IN_PROGRESS`, `PENDING` (skips `ON_HOLD` because the clock is paused, and the terminal trio because their breach state is set deterministically by `TicketService`)
- Both loops `chunkById(100)` to bound memory on large backlogs
- Both notification classes (`SlaWarningNotification`, `SlaBreachedNotification`) always send mail regardless of `mail_enabled` config
- **`public static bool $inProgress = false`** — set `true` at start of `handle()`, reset in `finally`. `Ticket::saved()` checks this flag and skips per-ticket `refreshPerformanceMetrics()` when `true` — prevents N redundant recalculations for N tickets of the same employee
- **Dedup recalc**: collects `$affectedEmployeeIds` during the breach sweep chunk loop; after all chunks complete, deduplicates and calls `refreshPerformanceMetrics()` once per unique employee_id

### De-dupe
- **Warning:** `tickets.sla_warning_sent_at` — skipped when it is today; stamped with `forceFill()->saveQuietly()` (no observer, no activity log) **before** the fan-out, so one warning per ticket per calendar day regardless of bell preferences (Phase 0 fix; test `SlaWarningDedupTest`)
- **Breach:** `alreadySentToday(Ticket, SlaBreachedNotification::class)` reads today's `notifications` rows and inspects `data.actions[*].url` for `/tickets/{id}` — portable across MySQL/SQLite. Safe here because the breach flips once and `sla_breach` is always-on in the bell

### Recipients
- `slaRecipients()` returns assignee (`$ticket->employee->user`) + creator (`User::find($ticket->created_by)`) + **group supervisor** (when `employee_id` is null but `group_id` is set — ensures the responsible supervisor is alerted before individual assignment)
- No admin fallback by design; admins see breach state through the panel widgets and the navigation badge
- Missing relations short-circuit silently (filter + unique('id') on the collect)

## Adding New Jobs
- Place the class under `app/Jobs/`
- For periodic jobs, register the schedule in `bootstrap/app.php` and document the cadence in this file
- For ticket-touching jobs, prefer dispatching from `TicketService` rather than the Observer to keep "observers trigger services, services contain logic"

## ProcessTeamTicketSla (team tickets)
- Scheduled every 5 min (`process-team-ticket-sla`, queued, `withoutOverlapping`)
- Response breach flag; resolution breach flag + attribution + `TeamTicketSlaBreached` (once, `breach_notified_at`); 80 % warning `TeamTicketSlaWarning` once per cycle (`warning_sent_at`, pauses excluded); auto-close of RESOLVED tickets past `auto_close_at`
- `CheckSlaBreaches` now filters `whereNull('team_id')`

- (Phase 4) The job now calls `TeamTicketNotifier` for warning / breach and escalates breached, still-open cycles after `general.sla_escalation_hours` (sets `escalated_at`)
