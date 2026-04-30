# Models Guide

## Task → Ticket Rename (in progress)
- Primary model: `App\Models\Ticket` (table: `tickets`)
- Backward compat alias: `App\Models\Task extends Ticket` — no logic, just a class alias
- All **new** code uses `Ticket`, never `Task`

## Turkish → English Column Mapping
| Turkish (legacy) | English (current) | Table |
|---|---|---|
| bolge_id | area_id | tickets, sla_policies, groups |
| lokasyon_id | sub_area_id | tickets, sla_policies |
| teknik_birim_id / birim_id | unit_id | tickets, groups, sla_policies |
| oncelik | priority | tickets, sla_policies |
| durum | status | tickets |
| ariza_tarihi | task_date | tickets |
| isim | name | groups |
| amir_id | employee_id (supervisor) | groups |
| cozum_suresi_dakika | deadline_minutes | sla_policies |

## Required Relationships
- **Ticket**: area, subArea, unit, group, employee, createdBy, completedBy, closedBy, statusHistories
- **TicketStatusHistory**: ticket, changedBy
- **Group**: company, area, unit, manager (Employee), members (GroupMember has employee)
- **SlaPolicy**: area, subArea, unit
- **Employee**: tasks (→ tickets), groupMemberships, managedGroups, user

## SoftDeletes — required on
`tickets`, `groups`, `sla_policies`, `employees`, `users`, `areas`, `sub_areas`

## Observer Hooks
- `TicketObserver` (registered in AppServiceProvider):
  - `creating`: call SlaService → set sla_deadline; generate ticket_no
  - `updating`: status → assigned: set assigned_at; → resolved: set resolved_at;
    → closed/completed: set closed_at, evaluate sla_breached
- Other models set `created_by` / `updated_by` / `deleted_by` directly in `booted()`
