# Jobs Guide

## Queue
- Intended driver: `database` (`.env.example`, test site `dev-ticket`). **Production currently runs `QUEUE_CONNECTION=sync`** — queued jobs and notifications execute inline (scheduler-dispatched jobs run inside `schedule:work`, request-time ones inside the HTTP request); switching prod to `database` is recommended
- All async work via `Job::dispatch()`; local dev uses `sync`
- Schedule entries live in `bootstrap/app.php` → `withSchedule()` callback (Laravel 12 — no `Kernel.php`)
- In production, supervisor runs `schedule:work` (1×) and `queue:work --tries=3 --max-time=3600` (2×), both as root. Workers pick up new code only when they restart (container restart or `--max-time` expiry)
- Current schedule: `employee:sync` every 5 min, `ProcessTeamTicketSla` every 5 min (`withoutOverlapping`), tomorrow's log file pre-created at 23:55 (`chmod 0777`)

## Adding New Jobs
- Place the class under `app/Jobs/`
- For periodic jobs, register the schedule in `bootstrap/app.php` and document the cadence in this file

## ProcessTeamTicketSla (team tickets)
- Scheduled every 5 min (`process-team-ticket-sla`, queued, `withoutOverlapping`)
- Response breach flag; resolution breach flag + attribution + `TeamTicketSlaBreached` (once, `breach_notified_at`); 80 % warning `TeamTicketSlaWarning` once per cycle (`warning_sent_at`, pauses excluded); auto-close of RESOLVED tickets past `auto_close_at`

- (Phase 4) The job now calls `TeamTicketNotifier` for warning / breach and escalates breached, still-open cycles after `general.sla_escalation_hours` (sets `escalated_at`)
