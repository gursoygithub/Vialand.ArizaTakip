# Services Guide

## Rule
All business logic lives in `app/Services/`. Never put business logic in Filament Resources,
Widgets, or Observers (observers trigger services, they don't contain logic).

## FcmService (`App\Services\FcmService`)
- `sendToUser(User, string $title, string $body, ?string $url): void` / `sendToUsers(Collection, …)` — fans out to each user's `fcm_tokens` rows
- **Data-only messages** — no `notification` key is sent to FCM; all payload is in the `data` field (`title`, `body`, `url`). This ensures the browser's `onMessage` handler fires in the foreground so Filament can render its own toast instead of the OS notification system intercepting the message.
- Tokens stored in `fcm_tokens` (`user_id`, `token`, `token_hash`, `is_active`)

## Async / Scheduled Work

## Other Services (data sync — SQL Server / LDAP)

These services handle external data synchronization and are scheduled or called from Artisan commands:

- `AuthService` — LDAP authentication (bind with the user's DN + password); delegates user create/update to `UserService`; also handles a local admin bypass login (`APP_ADMIN_USERNAME` / `APP_ADMIN_PASSWORD`, plain `===` compare, creates the user + `super_admin` role on first use). It reads these with `env()` at runtime — under `config:cache` (prod) they resolve to `null` unless set as real container env vars. Entry point is `AuthController` (`/auth`), **not** `Filament\Pages\Auth\LoginPage` (the panel's `->login()` is commented out). No login throttling.
- `UserService` — syncs user from LDAP `ActiveDirectory\User` to local `users` table (create or update, matched by `ldap_guid` OR `username`); maps LDAP attributes; new users get the `default` role. Writes `users.password = Hash::make(<AD password>)` on every login. Writes `users.employee_id = employees.employee_id` (the external personnel number / `UNIQUE_ID`, **not** `employees.id`). The Eloquent `User::employee()` relation joins on **email**, not on this column.
- `EmployeeService` — syncs employees from SQL Server `sqlsrv2` connection (`_TGRY_PERSONEL` table); batched upsert.
  - **Split upsert**: `processBatch()` splits buffer into `$withEmail` and `$withoutEmail` groups. Blank/null email rows upsert WITHOUT updating the email column — preserves existing admin-entered or previously synced value.
  - **Orphan user relinking**: after all batches complete, queries `User::whereNull('employee_id')` and links each to matching Employee by email. Logs count of relinked users.
  - **Company auto-creation**: `processBatch()` upserts companies via `normalized_name` (UPPER TRIM) before employee upsert — idempotent.
- `TechnicianService` — syncs technicians from SQL Server `sqlsrv2` connection; same pattern as EmployeeService
- `DailyReportService` / `ReportService` — **dead leftovers** from another project (attendance / card readings). Their target tables `daily_reports` and `reports` do not exist in this schema; the commands calling them (`report:*`, `reports:90days`, `cu-daily-report`) are not scheduled and would fail. `report:stream` is declared by two classes.

## Notifications (Filament-compatible)
- All ticket notifications return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the panel bell renders title/body/icon/action
- Mail: the 8 targeted events (see `app/Notifications/CLAUDE.md`) always send mail regardless of `mail_enabled` config; `TicketStatusChangedNotification` and `TicketCommentNotification` are database-only
- Per-user channel preferences via `UserNotificationPreference` (`mail_enabled`, `database_enabled` per event type) — see `app/Notifications/CLAUDE.md`

## WorkingTimeCalculator (`App\Services\WorkingTimeCalculator`)
- `addWorkingMinutes($from, $minutes, ?WorkCalendar)` / `workingMinutesBetween($from, $to, ?WorkCalendar)`
- NULL calendar or 24/7 calendar that ignores holidays = plain clock time; otherwise only `hoursFor(weekday)` windows count, observed holidays skipped. Throws after 400 days without working time
- Holidays are cached per instance — resolve a new instance (it is not a singleton)

## TicketWorkflowService (`App\Services\TicketWorkflowService`) — team tickets only
| Action | Who | From → To | SLA effect |
|---|---|---|---|
| `open` | requester | — → OPEN | cycle 1 starts now; required custom fields enforced |
| `assign` | team supervisor (or super_admin) | OPEN/ASSIGNED/IN_PROGRESS → ASSIGNED | new assignment period, `remaining_ratio_at_start` recorded, deadline unchanged |
| `selfAssign` | team member, if `allow_self_assign` | OPEN → ASSIGNED | same |
| `startWork` | assignee | ASSIGNED → IN_PROGRESS | first start stops response clock (`responded_at`, `response_breached`) |
| `hold` / `resume` | assignee / assignee or supervisor | IN_PROGRESS ⇄ ON_HOLD | `HoldReasonEnum` only; paused working minutes push both deadlines |
| `resolve` | assignee | IN_PROGRESS → RESOLVED | outcome decided now; clock pauses awaiting confirmation; `auto_close_at` = now + setting |
| `confirm` | requester | RESOLVED → CLOSED | cycle sealed `on_time`/`breached`; `reopen_until` = now + setting |
| `rejectResolution` ("Çözülmedi") | requester, reason | RESOLVED → IN_PROGRESS | same cycle continues, waiting time not charged, `resolution_rejections`++ |
| `autoClose` | scheduler | RESOLVED → CLOSED | as confirm, `closed_by` NULL |
| `reopen` | requester within window | CLOSED → ASSIGNED (resolver) or OPEN | new cycle from now; previous outcome kept |
| `cancel` | requester before work starts, else supervisor | active → CANCELLED | outcome `cancelled`; a breach already passed stays flagged |
| `reject` | supervisor, `RejectionReasonEnum` | OPEN/ASSIGNED → REJECTED | outcome `rejected` |
| `transfer` | supervisor, reason | OPEN/ASSIGNED/IN_PROGRESS → OPEN | old cycle `transferred` (charged to nobody) — or `breached` when its deadline had already passed (charged to whoever held it at the deadline, no resolver); new team clock from now |
- The priority is the requester's choice at opening and is never changed afterwards (it is the SLA promise). A wrong priority is corrected in the reports through an SLA exemption (`ExemptionReasonEnum::WrongPriority`), not on the request
- Violations throw `App\Exceptions\TicketWorkflowException` with a Turkish user message
- `flagResolutionBreach` / `flagResponseBreach` / `elapsedRatio` are the scheduler entry points

## TeamPerformanceService (Phase 5)
- `report($from, $to, User $viewer, ['team_ids' => [...], 'ticket_type_id' => ?])` → `summary`, `teams`, `types`, `people`, `companies`
- Cycles are selected by `ended_at` in range and limited to `teamsVisibleTo($viewer)`
- **Counts** only `on_time` / `breached` cycles with `exempted = false`; cancelled / rejected / transferred / exempted never count (exempted and "cancelled after breach" are listed separately)
- **Person**: denominator = cycles they resolved (last assignment of the cycle) ∪ cycles whose breach is charged to them (`breach_attribution = person`); compliance = 1 − charged / denominator. Handover and dispatch breaches are team-only
- `personReport(Employee)` → `summary`, `last30` (by `ended_at`), `teams`, `priorities` — same person rules as `report()`, all teams, no viewer filter (the employee page is already scoped); adds `target` (avg cycle `success_threshold`) and `rated`. `currentWorkload(Employee)` → open team tickets held (`ACTIVE_STATUSES`) and those with `sla_breached`
- Quality: `reopened` (a next cycle on the same team exists) and `resolution_rejections` go to the resolver; satisfaction to the resolver and team; resolution time = working minutes `started_at → resolved_at` minus `paused_minutes`

## SlaExemptionService (Phase 5)
- `canRequest(cycle, user)`: breached, not exempted, no pending/approved request; user = charged employee, team supervisor or super_admin
- `canDecide(exemption, user)`: pending; has `sla.exemption.approve` (or super_admin); not requester / charged employee / team supervisor / team member
- `approve` (sets `cycle.exempted`) / `reject` (note required); notifies approvers on request and the requester on decision (`TeamTicketNotifier`)
