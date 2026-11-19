# Filament Guide

## Layout
- `Resources/` — one Resource per model
- `Resources/{Name}Resource/Pages/` — List, Create, Edit, View; **Ticket** also has the View page hosting the timeline + transition actions
- `Resources/{Name}Resource/RelationManagers/` — RelationManagers for nested relationships (e.g. `UserResource/CompanyAccessRelationManager`)
- `Pages/` — custom pages: `TeamPerformanceReport`, `NotificationPreferences`, `ManageGeneralSettings`
- `Pages/Auth/` — `LoginPage` (custom LDAP login) and `EditProfile` (profile edit page)
- `Widgets/` — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart`

## EmployeeResource
- List: name, email, **company** (`company.name` — searchable, sortable, toggleable, `—` when empty), active / breached ticket counts, SLA score + threshold. Filter: **company** (multi-select via `Employee::company()`)
- View page identity section (4 columns): name, email, phone, **company**

## Single Responsibility
- One Resource per model; one Widget per chart/metric
- Business logic stays in `app/Services/` — never inline in Resources

## Resource Scope (non-Ticket)
All non-Ticket Resources use the `ScopedByVisibility` trait
(`app/Filament/Concerns/ScopedByVisibility`). Provides:
- Default `getEloquentQuery()` scoped to `created_by = auth()->id()`
- Override with `protected static string $viewAllPermission = 'view_all_X'` to
  allow users with that permission to see all records
- `super_admin` bypasses the scope via an explicit `hasRole('super_admin')` check in the trait. super_admin also passes every permission check through Shield's Gate::before (`define_via_gate = true`, see root CLAUDE.md)
- To add extra WHERE clauses on top of the scope, override `getEloquentQuery()`,
  call `static::applyVisibilityScope(parent::getEloquentQuery())`, then chain
  (see `UserResource` for example)

**Ticket is NOT covered by this trait** — it uses `Ticket::scopeVisibleBy()`
with its own 4-branch logic. See root CLAUDE.md § "Ticket Visibility".

## Page access exceptions
- `NotificationPreferences` → **any authenticated user** (deliberate — personal settings page, no `page_` permission)

## Login
- The panel's `->login()` is commented out; users log in through `AuthController` at `/auth` (`resources/views/auth/login.blade.php`). `Pages/Auth/LoginPage` and `Pages/Auth/EditProfile` exist but are not wired via `->login()` / `->profile()` (both calls are commented out in `DashboardPanelProvider`)
- Bell: `databaseNotifications()` + `databaseNotificationsPolling('5s')`

## DashboardPanelProvider
- `->databaseNotifications()` enabled — bell auto-renders our `FilamentNotification` payloads
- `->plugins([...])` registers Shield; FCM init JS (`resources/views/filament/fcm-init.blade.php`) is registered via `->renderHook(PanelsRenderHook::BODY_END, ...)`, not as a plugin

## Conventions
- Field labels via `__('ui.*')` keys — `ui.task_date` resolves to "Arıza Tarihi"
- Section icons use `heroicon-o-*` outlines
- For per-row SLA badges, prefer `Ticket::getSlaStatusLabel()` and the live `now()` comparison; the `sla_breached` column is for **filtering**, not display
- List row actions: wrap them in `Tables\Actions\ActionGroup::make([...])`
  for the compact three-dot menu, with `ViewAction`, `EditAction`,
  `DeleteAction` in that order. Apply per-action `->visible(...)` gates
  inside the group rather than hiding the whole group

## Request model admin (Phase 1) — navigation group "Panel Yönetimi"
- `TicketTypeResource` (List/Create/View/Edit): info section + `form_fields` Repeater (label → auto `key` slug, `key` regex `^[a-z][a-z0-9_]*$` + `distinct()`, type, options only for select, required, help). Delete only when the type has no teams and no tickets (otherwise deactivate)
- `TeamResource` (List/Create/View/Edit): type, name, supervisor + members selects (server-side search, label "Ad (Şirket)" via `TeamResource::employeeLabel`), calendar (empty = 7/24), self-assign toggle. SLA Repeater bound to `slaPolicies`: fixed 4 rows (not addable/deletable), priority disabled+dehydrated, `resolution_minutes` `gte(response_minutes)`. Delete only when the team has no tickets
- `WorkCalendarResource` (ManageRecords, modal): 7 weekday rows of TimePickers (`working_hours.{day}.start/end`, `requiredWith` + `after`); `WorkCalendarResource::normalize()` drops closed days / clears hours for 24/7 (called from Create and Edit actions); `summarize()` for the table
- `HolidayResource` (ManageRecords): date (unique) + name; "Yaklaşanlar" filter on by default
- `ManageGeneralSettings`: + "Talep Süreci Ayarları" (`auto_close_days`, `reopen_window_days`)

## TeamTicketResource ("Talepler", `/requests`) — Phase 3
- **Create** (`CreateTeamTicket`): type picker (ToggleButtons, `?type=` preselects) → active teams of that type (supervisor hint + SLA preview) → priority → location (hidden / optional / required per type) → the type's `form_fields` rendered by `TeamTicketResource::customFieldComponents()` into `custom_fields.*` → description + attachments. `handleRecordCreation` calls `TicketWorkflowService::open` (team must match type; unknown custom keys dropped)
- **List** (`ListTeamTickets`): tabs Bana Atananlar / Ekibimin Talepleri / Yönettiğim Ekipler (active statuses only) / Açtıklarım / Tümü; default tab = assigned (employees), all (super_admin / view.all), else created. SLA badge from the current cycle (`slaLabel` / `slaColor`), polls 60 s
- **View** (`ViewTeamTicket`): header actions mapped 1:1 to the workflow service (Üzerime Al, Ata/Devret, İşleme Al, Beklet, Devam Et, Çözüldü, Onayla, Çözülmedi, Yeniden Aç, Not Ekle; "Diğer İşlemler": öncelik, aktar, reddet, iptal). Visibility = UX only; the service re-checks. Infolist: request, people (with companies), SLA panel (response, resolution, state, breach owner, targets/calendar/paused), attachments, history (`filament.partials.team-ticket-timeline`, inline styles)

## Phase 5 screens
- `Pages/TeamPerformanceReport` (Raporlar): date range (default this month), teams, type filters; summary cards, team / people / type / company tables; CSV export (`ticket.export`). View `filament.pages.team-performance-report` uses inline styles
- `SlaExemptionResource` (Raporlar, `/sla-exemptions`): list only (no create/edit/delete); approvers see all (pending by default, badge = pending count) and get Approve / Reject row actions where `canDecide`; others see their own requests
- `ViewTeamTicket`: "Onayla ve Kapat" asks for an optional 1–5 score; "Diğer İşlemler › SLA Muafiyeti İste" when `canRequest`; SLA panel shows exemption status and the score

## Panel layout (faz-6)
- **Rule: every screen shows the team model only** — list columns, filters, infolists and the relation managers under a detail page included. Shared resources that had legacy content branch on `config('legacy.enabled')` and keep the legacy version for the switch-on case (phpunit runs with it on, so legacy tests stay valid; new-model tests set `config(['legacy.enabled' => false])`)
- `EmployeeResource`: `teamTable()` — team count badge (`teamCountLabel()`: "4 ekip · 2 amir", names from `teamLabels()` in the tooltip), requests on them / past SLA (team tickets, `TeamPerformanceService::ACTIVE_STATUSES`), company + team filters; `ViewEmployee::teamInfolist()` — workload now, last 30 days / all time from `TeamPerformanceService::personReport()`, team and priority breakdowns (colour vs. the cycles' `success_threshold` average); relation managers `TeamTicketsRelationManager` (requests held now or before, `visibleInTeamWorkflow`, row → `panelUrl()`) and `TeamMembershipsRelationManager` (member ∪ supervised teams, role Üye / Amir / Amir + Üye). Switch on: the old table, `legacyInfolist()`, `TicketsRelationManager`, `GroupMembershipsRelationManager`
- In a `RepeatableEntry` over arrays, `$record` in child closures is the **page record**, not the row — read the row with `$component->getContainer()->getState()`
- New resources go into one of the groups listed in `DashboardPanelProvider::navigationGroups()`; keep `$navigationSort` in steps of 10
- Dashboard: `Widgets/RequestOverviewStats`, `MyRequestQueue`, `RequestsByStatusChart`, `OpenRequestsByTypeChart` — data scoped per viewer, so they are not Shield-gated
