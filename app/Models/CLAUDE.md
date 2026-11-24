# Models Guide

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
- **Employee**: tickets, groupMemberships, managedGroups, user, company (`company_id`, added in Phase 0), slaPolicies (BelongsToMany via `employee_sla_policies`)

## Other Models (not primary domain)
- **FcmToken**: `user_id`, `token`, `token_hash`, `is_active`; belongs to User
- **UserNotificationPreference**: per-user channel switches (`mail_enabled`, `database_enabled`) per notification type

## SoftDeletes — required on
`tickets`, `groups`, `sla_policies`, `employees`, `users`, `areas`, `sub_areas`

## Lifecycle Timestamps (Ticket)
- The view-page lifecycle strip queries `ticket_status_histories` for the first IN_PROGRESS row **scoped to `created_at >= assigned_at`** (no dedicated column for "İşleme Alındı"); the scope ensures pre-reopen IN_PROGRESS rows are excluded

## User ↔ Employee link
- `User::employee()` = `hasOne(Employee, 'email', 'email')`; `Employee::user()` = `belongsTo(User, 'email', 'email')` restricted to `status = ACTIVE`, `username NOT NULL`, not soft-deleted. Changing an email on either side breaks the link
- `users.employee_id` is a separate column holding `employees.employee_id` (personnel number), not used by these relations

## `query()` overrides (gotchas)
- `User::query()` always adds `where('username', '!=', env('APP_ADMIN_USERNAME', 'sa'))` — hides the local admin **and every user with `username IS NULL`**. Only explicit `User::query()` calls are affected; `User::find()` / `User::where()` are not
- `SubArea::query()` applies its own `created_by` scope and dereferences `auth()->user()` — **throws when unauthenticated** (queue / CLI). Only explicit `SubArea::query()` calls are affected

## Hard-coded eager loads
Several relations always eager-load: `Ticket::area` (+company, subAreas), `Company::areas` (+subAreas, createdBy, updatedBy, deletedBy). Touching these in loops cascades. Prefer `->without(...)` or a dedicated query.

## ticket_no generation
`Ticket::booted()` creating: `TKT-{year}-{count(withTrashed, this year) + 1}` zero-padded to 5. Not concurrency-safe; collides if rows are force-deleted.

## Media
- Collection `task_attachments` on `Ticket` — disk `s3`, **multi-file** (no `singleFile()`); the create form caps at 5 files via `maxFiles(5)`

## Request model (Phase 1)
- **TicketType**: teams, activeTeams, tickets; scopes `active()`, `ordered()`; casts `location_requirement` → `LocationRequirementEnum`, `form_fields` → array
- **Team**: ticketType, supervisor (Employee), workCalendar, members (BelongsToMany Employee via `team_members`), slaPolicies (ordered by priority), tickets; `slaPolicyFor(TaskPriorityEnum)`, `runsAroundTheClock()`; scope `active()`
- **TeamSlaPolicy**: team; `priority` cast to `TaskPriorityEnum`
- **WorkCalendar**: teams; `hoursFor(int $isoWeekday): ?array{start,end}` (24/7 → 00:00–24:00, closed → null)
- **Holiday**: `date` is stored as plain `Y-m-d` (set mutator) so the unique rule works on every driver
- **Employee**: + teams (member), supervisedTeams; **Ticket**: + ticketType, team, `custom_fields` (array)
- `Concerns\RecordsAuthors` fills created_by / updated_by / deleted_by and adds createdBy / updatedBy relations

## SLA engine models (Phase 2)
- **TicketSlaCycle**: ticket, team, workCalendar, breachEmployee, assignments; `isPaused()`, `isOpen()`, scope `running()`; enum casts `priority`, `breach_attribution`, `outcome`; immutable datetimes
- **TicketAssignment**: ticket, cycle, employee, assignedBy; `END_*` reason constants
- **Ticket** (+): slaCycles, currentCycle (max cycle_no), assignments, currentAssignment (open period), `usesTeamWorkflow()`; casts `hold_reason` → `HoldReasonEnum`, `auto_close_at`, `reopen_until`
- Enums: `HoldReasonEnum`, `RejectionReasonEnum`, `BreachAttributionEnum`, `SlaCycleOutcomeEnum` (`countsForPerformance()` = on_time / breached only)

## One person, several HR records (TC kimlik no)
HR opens a new `employees` row each time someone moves company or project
(old row inactive, sometimes two active at once). The TC stays the same, so
**a person = every record with the same `tc_no`**:
- `Employee::personIds()` / `personRecords()`, `User::employeeIds()` (the
  account's person). Always use these for "is this me / my team / my work"
  checks — never a single `employee->id` (visibility scope, supervisor /
  assignee / member checks, CC, tabs, widgets, reports, exemptions).
- History is never merged: tickets, assignments, holds keep the record they
  were done with; reports and the personnel page add the records up.
- `Employee::scopeOnePerPerson()` keeps pickers to one row per person
  (newest active record). `User::employee()` prefers the active record.
- `employee:sync` moves team supervisor/member links from an old record to
  the person's active one (same TC) and switches off people HR no longer lists.
