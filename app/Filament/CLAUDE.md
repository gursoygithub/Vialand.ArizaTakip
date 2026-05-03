# Filament Guide

## Layout
- `Resources/` — one Resource per model (Area, Company, Employee, Group, SlaPolicy, SubArea, Subcontractor, SubcontractorEmployee, Ticket, Unit, User)
- `Resources/{Name}Resource/Pages/` — List, Create, Edit, View; **Ticket** also has the View page hosting the timeline + transition actions
- `Resources/{Name}Resource/RelationManagers/` — RelationManagers for nested relationships (e.g. `UserResource/CompanyAccessRelationManager`)
- `Pages/` — custom pages: `CompanySetupWizard`, `PerformanceDashboard`, `NotificationPreferences`, `ManageGeneralSettings`, `TaskKpiPage`
- `Widgets/` — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart`
- `Exports/` — Filament export classes

## Single Responsibility
- One Resource per model; one Widget per chart/metric
- Business logic stays in `app/Services/` — never inline in Resources

## TicketResource Specifics
- List page (`Pages/ListTickets`) polls every 60 s for live SLA countdown; tabs include OPEN / ASSIGNED / IN_PROGRESS / ON_HOLD / RESOLVED / CLOSED / **breached** (column-backed via `Ticket::scopeSlaBreached`)
- Row actions: wrapped in `Tables\Actions\ActionGroup::make([...])` (the
  three-dot menu) so the row stays compact. Order inside the group:
  `ViewAction`, `EditAction`, `DeleteAction`. Edit/Delete are creator or
  `super_admin` only (mirrors `TicketPolicy::update/delete`).
- Form: `task_date` and `description` paired in `Grid(2)` with `task_date->maxDate(today)` and `description->required()->minLength(10)`; attachments use multi-file upload (max 5, 10 MB each, jpeg/png/webp/pdf)
- View page header actions:
  - One transition button per allowed next status (filters by permission + reopen rules)
  - `Ata` / `Yeniden Ata` (employee picker, `ticket.assign`)
  - `Add comment` (creator or `ticket.assign`)
  - `Bildirim Aç` / `Bildirim Sus` toggles `ticket_mutes` for the viewer; only visible to participants (creator / assignee / anyone in `ticket_status_histories.changed_by`)
  - `Edit` mirrors the list action gate; `Delete` via the `TicketPolicy`

## CompanySetupWizard
- Hard-blocks each step until prerequisites pass — see Setup Chain Rules in `app/Services/CLAUDE.md`
- `shield:generate --all` rewrites three policies; restore them from git after running (warning is in the project root `CLAUDE.md`)

## DashboardPanelProvider
- `->databaseNotifications()` enabled — bell auto-renders our `FilamentNotification` payloads
- `->plugins([...])` registers Shield + the FCM init JS (`resources/views/filament/fcm-init.blade.php`)

## Conventions
- Field labels via `__('ui.*')` keys — `ui.task_date` resolves to "Arıza Tarihi"
- Section icons use `heroicon-o-*` outlines
- For per-row SLA badges, prefer `Ticket::getSlaStatusLabel()` and the live `now()` comparison; the `sla_breached` column is for **filtering**, not display
- List row actions: wrap them in `Tables\Actions\ActionGroup::make([...])`
  for the compact three-dot menu, with `ViewAction`, `EditAction`,
  `DeleteAction` in that order. Apply per-action `->visible(...)` gates
  inside the group rather than hiding the whole group
