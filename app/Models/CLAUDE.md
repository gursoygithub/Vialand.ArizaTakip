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
  - `creating`: SlaService → set `sla_deadline`; generate `ticket_no`
  - `saving`: stamp `assigned_at` when an employee is attached; flip `sla_breached` when deadline has passed and status is non-terminal; clear it when a terminal status resolves on time
  - `updated`: notify on direct `employee_id` reassignment (status-change notifications go through TicketService → event)
- Other models set `created_by` / `updated_by` / `deleted_by` directly in `booted()`

## Media
- Collection `task_attachments` on `Ticket` — disk `s3`, **multi-file** (no `singleFile()`); the create form caps at 5 files via `maxFiles(5)`
