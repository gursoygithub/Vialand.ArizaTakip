# Models Guide

## Task → Ticket Rename (in progress)
- Primary model: `App\Models\Ticket` (table: `tickets`)
- Backward-compat alias: `App\Models\Task extends Ticket` — no logic
- All **new** code uses `Ticket`, never `Task`

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
- **Ticket**: area, subArea, unit, group, employee, createdBy, completedBy, closedBy, statusHistories, mutes
- **TicketStatusHistory**: ticket, changedBy
- **TicketMute**: ticket, user (composite-unique on `(ticket_id, user_id)`)
- **Group**: company, area, unit, manager (Employee), members (GroupMember has employee)
- **SlaPolicy**: area, subArea, unit
- **Employee**: tasks (→ tickets), groupMemberships, managedGroups, user

## SoftDeletes — required on
`tickets`, `groups`, `sla_policies`, `employees`, `users`, `areas`, `sub_areas`

## Ticket Helpers (live SLA display)
- `Ticket::getRemainingMinutes(): int` — minutes vs sla_deadline (negative when breached, 0 if no policy)
- `Ticket::getSlaStatusLabel(): string` — label like `'2sa 30dk kaldı'` / `'İhlal 1sa'` / `'⏸'` / `'✓ Zamanında çözüldü'` — terminal states derive outcome from `resolved_at` ?? `closed_at` vs `sla_deadline`
- Use these for **per-row** badge rendering; never read `sla_breached` directly for the live label

## SLA Breach Architecture
- `tickets.sla_breached` is the indexed source of truth for filters/badges (composite index `(sla_breached, status)`)
- Three writers keep it current — see `app/Services/CLAUDE.md` and `app/Jobs/CLAUDE.md`
- `Ticket::scopeSlaBreached` is a one-liner on the column; `Ticket::scopeVisibleBy` applies the permission scope (`view.all` / `view.group` / `view.own`) plus the company filter

## Observer Hooks
- `TicketObserver` (registered in AppServiceProvider):
  - `creating`: resolve SLA policy → set `sla_deadline`; default `status` (ASSIGNED if `employee_id` set, else OPEN); stamp `assigned_at` if created already-assigned; generate `ticket_no`
  - `saving` (every save):
    1. Stamp `assigned_at` when `employee_id` is set and `assigned_at` is empty
    2. **Recalculate `sla_deadline` on priority change** — only when `exists && isDirty('priority') && area_id && priority` and **status is non-terminal** (excludes RESOLVED/CLOSED/CANCELLED). Rebases as `now() + policy.deadline_minutes + total_on_hold_minutes`. Runs BEFORE the breach flip so the same save evaluates the fresh deadline. Creating path is owned by `creating()` (not this branch)
    3. Flip `sla_breached = true` when `sla_deadline` has passed and status is non-terminal (excludes RESOLVED/CLOSED/COMPLETED/CANCELLED)
    4. Clear `sla_breached = false` when a terminal status save sees deadline still in the future
  - `updated`: notify on direct `employee_id` reassignment (when status didn't change AND `$skipReassignNotification` is false). Sends `TicketAssignedNotification` AND fires FCM push (actor-name-prefixed body: `"{actor} tarafından atandı — {area} / {priority}"`); respects `ticket_mutes` and `wantsNotification('ticket_assigned', 'database')`
  - `static $skipReassignNotification` — `TicketService::reassign` toggles this around `$ticket->update(['employee_id'])` to prevent the observer from double-firing alongside the service's own notify path
- Other models set `created_by` / `updated_by` / `deleted_by` directly in `booted()`

## Lifecycle Timestamps (Ticket)
- `created_at` / `assigned_at` / `on_hold_since` / `resolved_at` / `closed_at` / `closed_by` — written by `TicketObserver` + `TicketService::transition` matchers
- The view-page lifecycle strip and `Talep Geçmişi` timeline both consume these directly; the strip queries the first IN_PROGRESS row from `ticket_status_histories` for the "İşleme Alındı" timestamp (no dedicated column)

## Media
- Collection `task_attachments` on `Ticket` — disk `s3`, **multi-file** (no `singleFile()`); the create form caps at 5 files via `maxFiles(5)`
