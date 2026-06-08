# Models Guide

## Task → Ticket Rename (complete)
- Primary model: `App\Models\Ticket` (table: `tickets`)
- The legacy `Task` model alias and the `tasks()` relation methods on
  Employee/Unit/SubArea/User/Technician have all been removed; every
  caller now uses `Ticket` and `tickets()` directly.
- The legacy schema columns `unit_description`, `completed_by`,
  `due_date`, and `sla_outcome` were dropped by
  `2026_05_04_120000_drop_legacy_columns_from_tickets`. Their model-level
  `$fillable` / `$casts` / `completedBy()` relation / `booted()` write
  hook were removed alongside.
- Defensive references to `TaskStatusEnum::PENDING` / `COMPLETED` /
  `WINTER_MAINTENANCE` remain in a small set of places (terminal-status
  arrays in `TicketService`, `TicketObserver`, `CheckSlaBreaches`,
  `TicketResource`, `ViewTicket`, `TicketStatsOverview`,
  `TicketStatusChangedNotification`). These are intentional — they
  ensure pre-Reform tickets that exist in production data still render
  in lists/timelines/notifications. **Do not add new uses of these
  enum cases**; the Legacy Task Migration Rule below applies to new
  code.

## Turkish → English Column Mapping
| Turkish (legacy) | English (current) | Table |
|---|---|---|
| bolge_id | area_id | tickets, sla_policies, groups |
| lokasyon_id | sub_area_id | tickets, sla_policies |
| teknik_birim_id / birim_id | unit_id | tickets, groups, sla_policies |
| oncelik | priority | tickets, sla_policies |
| durum | status | tickets |
| ariza_tarihi | task_date | tickets (UI label is "Arıza Tarihi") |
| isim | name | groups |
| amir_id | employee_id (supervisor) | groups |
| cozum_suresi_dakika | deadline_minutes | sla_policies |

## Required Relationships
- **Ticket**: area, subArea, unit, group, employee, createdBy, closedBy, reopenedBy, statusHistories, mutes
- **TicketStatusHistory**: ticket, changedBy
- **TicketMute**: ticket, user (composite-unique on `(ticket_id, user_id)`)
- **Group**: company, area, unit, manager (Employee), members (GroupMember has employee)
- **SlaPolicy**: area, subArea, unit
- **Employee**: tickets, groupMemberships, managedGroups, user, slaPolicies (BelongsToMany via `employee_sla_policies`)

## Other Models (not primary domain)
- **Technician**: legacy model from external sync (`TechnicianService`); separate from Employee
- **EmployeeSlaPolicy**: Pivot model for `employee_sla_policies` table
- **FcmToken**: `user_id`, `token`, `token_hash`, `is_active`; belongs to User
- **UserNotificationPreference**: per-user channel switches (`mail_enabled`, `database_enabled`) per notification type
- **Subcontractor** / **SubcontractorEmployee**: external contractor management

## SoftDeletes — required on
`tickets`, `groups`, `sla_policies`, `employees`, `users`, `areas`, `sub_areas`

## Ticket Helpers (live SLA display)
- `Ticket::getRemainingMinutes(): int` — minutes vs sla_deadline (negative when breached, 0 if no policy)
- `Ticket::getSlaStatusLabel(): string` — label like `'2sa 30dk kaldı'` / `'İhlal 1sa'` / `'⏸'` / `'✓ Zamanında çözüldü'`. **CANCELLED returns `__('ui.ticket_cancelled')` ("İptal Edildi") immediately** before the terminal-outcome branch — cancelled tickets have no SLA outcome to display. Terminal states (RESOLVED/CLOSED) then derive outcome from `resolved_at` ?? `closed_at` vs `sla_deadline`.
- Use these for **per-row** badge rendering; never read `sla_breached` directly for the live label

## SLA Breach Architecture
- `tickets.sla_breached` is the indexed source of truth for filters/badges (composite index `(sla_breached, status)`)
- Three writers keep it current — see `app/Services/CLAUDE.md` and `app/Jobs/CLAUDE.md`
- `Ticket::scopeSlaBreached` is a one-liner on the column; `Ticket::scopeVisibleBy` applies the permission scope (`view.all` / `view.group` / `view.own`) plus the company filter

## Observer Hooks
- `TicketObserver` (registered in AppServiceProvider):
  - `creating`: resolve SLA policy → set `sla_deadline`; default `status` (ASSIGNED if `employee_id` set, else OPEN); stamp `assigned_at` if created already-assigned
  - `created`: write initial `TicketStatusHistory` row (from=null, to=status); notify assignee if created already-assigned (`notifyAssignedUser`); else notify group supervisor if group_id set (`notifyGroupSupervisor`)
  - `saving` (every save):
    1. Stamp `assigned_at` when `employee_id` is set and `assigned_at` is empty
    2. **Recalculate `sla_deadline` on priority change** — only when `exists && isDirty('priority') && area_id && priority` and **status is non-terminal** (excludes RESOLVED/CLOSED/CANCELLED). Rebases as `now() + policy.deadline_minutes + total_on_hold_minutes`. Runs BEFORE the breach flip so the same save evaluates the fresh deadline. Creating path is owned by `creating()` (not this branch)
    3. Flip `sla_breached = true` when `sla_deadline` has passed and status is non-terminal (excludes RESOLVED/CLOSED/COMPLETED/CANCELLED)
    4. Clear `sla_breached = false` when a terminal status save sees deadline still in the future
  - `updated`: notify on direct `employee_id` reassignment (when status didn't change AND `$skipReassignNotification` is false). Uses `wasChanged('employee_id')` / `wasChanged('status')` — **always use `wasChanged()` inside `saved()`/`updated()` hooks, never `isDirty()`** (by the time `saved` fires, `isDirty()` has already been cleared). Sends `TicketAssignedNotification` AND fires FCM push (actor-name-prefixed body: `"{actor} tarafından atandı — {area} / {priority}"`); respects `ticket_mutes` and `wantsNotification('ticket_assigned', 'database')`
  - `static $skipReassignNotification` — `TicketService::reassign` toggles this around `$ticket->update(['employee_id'])` to prevent the observer from double-firing alongside the service's own notify path

- `Ticket::booted()` closures (in the model itself, not in the observer):
  - `creating`: set `created_by`, generate `ticket_no` (TKT-YYYY-NNNNN), set default `status` (ASSIGNED if `employee_id` set, else OPEN). Runs before `TicketObserver::creating` (model boot beats observer order); both set the same defaults.
  - `saved`: forgets `dashboard_stats_overview` and `ticket_target_{id}` cache keys on every save; guards with `wasChanged(['sla_breached','resolved_at','employee_id'])` — **only those three column changes trigger a `refreshPerformanceMetrics()` recalculation**. Second guard: if `CheckSlaBreaches::$inProgress` is `true`, skips `refreshPerformanceMetrics()` call — the job handles recalc once per unique employee after all chunks complete. Uses `Employee::find($ticket->employee_id)` (not the cached relation) to bypass stale FK when `employee_id` itself just changed in the same save.
  - `updating`: set `updated_by`
  - `deleting`: set `deleted_by`

- Other models set `created_by` / `updated_by` / `deleted_by` directly in `booted()`

## Lifecycle Timestamps (Ticket)
- `created_at` / `assigned_at` / `on_hold_since` / `resolved_at` / `closed_at` / `closed_by` — written by `TicketObserver` + `TicketService::transition` matchers
- **Reopen resets the cycle**: `TicketService::transition` (terminal → ASSIGNED) clears `closed_at` / `closed_by` / `resolved_at` / `assigned_at`; the same save's `TicketObserver::saving` re-stamps `assigned_at = now()` because `employee_id` is preserved. This makes `assigned_at` the start-of-current-cycle marker
- The view-page lifecycle strip queries `ticket_status_histories` for the first IN_PROGRESS row **scoped to `created_at >= assigned_at`** (no dedicated column for "İşleme Alındı"); the scope ensures pre-reopen IN_PROGRESS rows are excluded

## Employee Performance

`Employee::refreshPerformanceMetrics()` — called from `Ticket::saved()` whenever `sla_breached`, `resolved_at`, or `employee_id` changes. Also called by `TicketService::reassign()` on both the old and new employee after the FK is updated.

**Formula ("sealed" cohort = resolved + breached)**:
- `success_count`: tickets where `resolved_at IS NOT NULL AND sla_breached = 0 AND sla_deadline IS NOT NULL` (excludes SLA-less tickets)
- `failed_count`: tickets where `resolved_at IS NOT NULL AND sla_breached = 1`
- CANCELLED tickets excluded via `whereNotIn('status', [CANCELLED])`
- Active (unresolved) breached tickets are **not counted** — only resolved tickets enter the cohort
- If `total = 0`: both `performance_score` and `current_threshold` are set to **`null`** — this means the employee has no sealed tickets yet (not a zero score)
- Otherwise: `performance_score = (success / total) * 100`; `current_threshold = avg(sla_policies.success_threshold)` for units the employee has sealed tickets in

**Nullability rule**: `performance_score = null` / `current_threshold = null` means "no data yet". A score of `0.0` is a real score (all breached). UI code must guard `is_null($score)` before rendering, and show gray/empty state rather than "0%".

## Media
- Collection `task_attachments` on `Ticket` — disk `s3`, **multi-file** (no `singleFile()`); the create form caps at 5 files via `maxFiles(5)`

## Legacy Task Migration Rule
The codebase was migrated from a "Task" system to a "Ticket" (Reform)
system. The following are BANNED in all new and existing code:

**Relations**
- `$record->tasks()` → use `$record->tickets()` instead
- Any relation named `tasks` on any model

**Columns / fields — never display or query**
- `due_date` → use `sla_deadline` or `closed_at`
- `completed_by` → use `closed_by`
- `unit_description` → legacy field, always empty in modern data
- `completedBy` relation → use `closedBy`

**Enum values — never use these legacy statuses**
- `TaskStatusEnum::PENDING` (value 0)
- `TaskStatusEnum::COMPLETED` (value 1)
- `TaskStatusEnum::WINTER_MAINTENANCE` (value 2)
Use only Reform-era statuses: `OPEN`, `ASSIGNED`, `IN_PROGRESS`,
`ON_HOLD`, `RESOLVED`, `CLOSED`, `CANCELLED`.

**Banned patterns**
- `formatStateUsing(fn ($state) => "<strong>{$state}</strong>")->html()`
  XSS risk — never interpolate user input into HTML.

**Performance source**
- `sla_outcome` (`'SUCCESS'`/`'FAILED'`) → use `sla_breached` (boolean).
  Canonical Reform-era SLA signal used by `PerformanceService` and all
  dashboards.

When encountering any of the above in existing code: flag and fix.
When writing new code: never use any of the above.
