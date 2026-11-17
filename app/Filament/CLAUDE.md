# Filament Guide

## Layout
- `Resources/` — one Resource per model (Area, Company, Employee, Group, SlaPolicy, SubArea, Subcontractor, SubcontractorEmployee, Ticket, Unit, User)
- `Resources/{Name}Resource/Pages/` — List, Create, Edit, View; **Ticket** also has the View page hosting the timeline + transition actions
- `Resources/{Name}Resource/RelationManagers/` — RelationManagers for nested relationships (e.g. `UserResource/CompanyAccessRelationManager`)
- `Pages/` — custom pages: `CompanySetupWizard`, `PerformanceDashboard`, `NotificationPreferences`, `ManageGeneralSettings`
- `Pages/Auth/` — `LoginPage` (custom LDAP login) and `EditProfile` (profile edit page)
- `Widgets/` — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart`
- `Exports/` — Filament export classes (`TaskExporter.php` — legacy name, exports tickets)

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
- `super_admin` bypasses the scope via an explicit `hasRole('super_admin')` check in the trait. There is **no** Gate::before bypass (`define_via_gate = false`) — reaching a resource still needs its Shield permission (see root CLAUDE.md)
- To add extra WHERE clauses on top of the scope, override `getEloquentQuery()`,
  call `static::applyVisibilityScope(parent::getEloquentQuery())`, then chain
  (see `UserResource` for example)

**Ticket is NOT covered by this trait** — it uses `Ticket::scopeVisibleBy()`
with its own 4-branch logic. See root CLAUDE.md § "Ticket Visibility".

## TicketResource Specifics
- List page (`Pages/ListTickets`) polls every 60 s for live SLA countdown; tabs include OPEN / ASSIGNED / IN_PROGRESS / ON_HOLD / RESOLVED / CLOSED / **breached** (column-backed via `Ticket::scopeSlaBreached`)
- Row actions: wrapped in `Tables\Actions\ActionGroup::make([...])` (the
  three-dot menu) so the row stays compact. Order inside the group:
  `ViewAction`, `EditAction`, `DeleteAction`. Edit/Delete are creator or
  `super_admin` only AND additionally `->hidden()` on terminal statuses
  (RESOLVED/CLOSED/CANCELLED) so the buttons don't appear at all on
  finalised tickets — `TicketPolicy::update` / `delete` enforce the same
  rule server-side, but the row-level `->hidden()` prevents the
  visible-button → click → 403 UX gap.
- **No bulk actions / no checkbox column** — the previous `bulk_assign`,
  `bulk_status`, and `DeleteBulkAction` were removed. `bulk_assign`
  bypassed the terminal-state lock (raw `$ticket->update` on closed
  tickets), `bulk_status` duplicated the per-row transition buttons on
  the View page, and `DeleteBulkAction` had no per-record pre-flight
  feedback for mixed selections. Removing the `->bulkActions(...)` block
  also removes the auto-rendered selection checkbox column.
- Form: `task_date` and `description` paired in `Grid(2)` with `task_date->maxDate(today)` and `description->required()->minLength(10)`; attachments use multi-file upload (max 5, 10 MB each, jpeg/png/webp/pdf)
- Form assignment chain: `area_id` → `sub_area_id` (required) → `unit_id` (only units with an SLA row for the area) → `group_id` (area + unit + user's companies/memberships) → `employee_id` (active members of the group). Area/sub-area/unit/group/employee are `disabled()` + `dehydrated(false)` on Edit. **`employee_id` on Create is not gated by `ticket.assign`** — any creator can pick an assignee. A reactive `sla_preview` Placeholder shows the resolved SLA. `type_id` is a hidden field pinned to `TaskTypeEnum::OPERATION`.
- Filters (in `->filters([...])` on the list table) — all `SelectFilter`s are `->multiple()`:
  - `company_id` (via `area.company_id`) — visible only to `super_admin` / `ticket.view.all`
  - `area_id`, `sub_area_id`, `unit_id` — relationship-driven, `->searchable()->preload()`. Options list **all** rows (not scoped to the viewer)
  - `status`, `priority` — enum-driven
  - `employee_id` — hidden for users without `ticket.view.group` / `ticket.view.all` / `super_admin`
  - `created_at` and `task_date` — `from` / `to|until` `DatePicker`s, `whereDate` bounds
  - **There is no `sla_status` filter.** Breached tickets are reached via the `breached` tab on `ListTickets` (`Ticket::scopeSlaBreached`).
- SLA badge column: label from `Ticket::getSlaStatusLabel()` (prefixed `✓`/`⚠` while counting down), color from private `TicketResource::slaColor()`.
- List page cost: 9 tab badges (`ListTickets::getTabs`) + nav badge + nav badge color each run `visibleBy()`; the page polls every 60 s.

### View page (`Pages/ViewTicket`)
- Lifecycle strip (`filament.partials.ticket-lifecycle-strip`) at top: 5 numbered steps — Açıldı / Atandı / İşleme Alındı / Çözüldü / Kapatıldı. Step "done" sources: `created_at`, `assigned_at`, first IN_PROGRESS `ticket_status_histories` row **scoped to `created_at >= assigned_at`** (the scope makes the strip reflect the *current* assignment cycle after a reopen), `resolved_at`, `closed_at`
- **Talep Geçmişi** (timeline) uses `ViewEntry` (not `TextEntry`) — TextEntry's prose typography clamps the partial to ~600px; ViewEntry renders the partial directly for true full width
- Timeline renderer (`renderTimeline`) handles 4 entry shapes: creation (from=null), transition (from!=to), reassignment (from==to AND note starts with `REASSIGN_NOTE_PREFIX`), comment (from==to, plain note). Comment cards expose Edit/Delete chips inside the 10-min window via `wire:click="mountAction('editComment'|'deleteComment', { history_id: N })"` mounted by `editCommentAction` / `deleteCommentAction`
- **ON_HOLD banner** — when `$record->status === ON_HOLD`, a `Section` containing a `ViewEntry` referencing `filament.infolists.components.on-hold-banner` renders as the first infolist item. The `since` and `duration` variables are computed at `infolist()` build time (same pattern as `$inProgressAt`): `$onHoldSince = $record->on_hold_since?->translatedFormat('d M Y H:i')` and `$onHoldDuration = DurationFormatter::minutes((int) $record->on_hold_since->diffInMinutes(now()))`. The banner Blade partial uses inline hex styles (no Tailwind tokens) because Tailwind JIT does not scan partials in `resources/views/filament/infolists/`.
- Header action visibility (source-of-truth — see top-of-file comment in `ViewTicket.php`):
  - `İşleme Al` / `Çözüldü` / **`Beklet`** (label is a verb, not the noun "Beklemede") → assigned employee's user OR `super_admin`
  - `İptal Et` / `Kapat` → creator OR `super_admin`
  - `Yeniden Aç` (CLOSED/RESOLVED/COMPLETED → **ASSIGNED**) → creator OR `super_admin` (`TicketPolicy::reopen`). Rendered by `buildTransitionActions` when `$to === ASSIGNED && $from ∈ terminal-set`; the OPEN → ASSIGNED case is still skipped in favor of the dedicated `Ata` action. Server-side gate enforced via `->before(fn () => Gate::authorize('reopen', $ticket))` so a forged mountAction call is rejected with 403 even if `visible()` is bypassed. The legacy `ticket.reopen` Spatie permission is no longer consulted
  - `Ata` / `Yeniden Ata` → `ticket.assign` AND **(creator OR current assignee's user OR group supervisor)**, not terminal (reopen is a separate button). Calls `TicketService::reassign`, which forces status back to ASSIGNED and resets the SLA window from any non-terminal status (see `app/Services/CLAUDE.md`)
  - `Düzenle` / `Sil` → creator OR `super_admin`. Unlike the list row actions these header buttons are **not** hidden on terminal statuses; `TicketPolicy` then refuses (403 / no-op)
  - `Not Ekle` / `Sesi Kapat`/`Sesi Aç` → any participant (creator / current assignee / anyone in `ticket_status_histories.changed_by`)
- Assign action options: prefer active group members (when `group_id` set), fall back to active employees in same company, capped at 500

### Create page (`Pages/CreateTicket`)
- **`beforeCreate()` SLA guard** — runs after Filament's own required-field validation but before the Eloquent record is created. Calls `SlaService::resolvePolicy()` with the submitted `area_id`, `sub_area_id`, `unit_id`, and `priority`. If the policy is `null`, throws `ValidationException` on `data.unit_id` with a Turkish error message. This prevents tickets from being created without an SLA policy (which would leave `sla_deadline = NULL`). The unit dropdown already filters to SLA-covered units, so this guard mainly catches the gap where a unit has SLA for some priorities but not the one selected.

### Edit page (`Pages/EditTicket`)
- **Hard 403 at `mount()`** — only ticket creator OR `super_admin` may reach it; `ticket.view.*` are read-only scopes and do NOT grant write access
- Terminal-state lock: `TicketPolicy::update` AND `TicketPolicy::delete` both return `false` for RESOLVED/CLOSED/CANCELLED **including super_admin** — reopen must go through `TicketService::transition` (CLOSED/RESOLVED → ASSIGNED), never via the Edit page; the same rule blocks deletion of finalised tickets (reopen first, then delete)
- `beforeSave` snapshots the original priority; `afterSave` calls `TicketService::notifyPriorityChange(...)` if priority changed (DB+FCM only, no mail)
- Priority change also triggers `TicketObserver::saving` to rebase `sla_deadline = now() + policy.deadline_minutes + total_on_hold_minutes` (non-terminal only)
- Redirects to `view` page after save

## PerformanceDashboard (`Pages/PerformanceDashboard`)

- `canAccess()` checks `page_PerformanceDashboard` (Shield layer 2). Any role granted this permission via the Shield UI panel can access the page.
- **Export** — the "Çıktı Al" button is wrapped in `@can('ticket.export')` so it is hidden from users without that permission. `submitExport()` enforces `abort_unless(...can('ticket.export'), 403)` as a server-side guard. The Livewire properties `$exportFormat` (string, default `'pdf'`) and `$exportSections` (array, default `['kpi','priority','region','team']`) back the modal form. `submitExport()` returns a `StreamedResponse` directly (Livewire 3 picks up returned download responses from actions).
- **Active preset highlighting** — `$activePreset` (nullable string Livewire property) stores the last clicked preset key (`'this_week'`, `'this_month'`, etc.). `setDateRange(string $preset)` sets it; `updatedDateFrom()` / `updatedDateTo()` clear it to `null` when the user manually changes a date input. Preset buttons use inline `style=` hex colors to avoid Tailwind purge issues in the custom Blade view.
- **PDF export** — uses `barryvdh/laravel-dompdf`. Key decisions: (a) logo is base64-encoded at render time via `file_get_contents(public_path('img/gursoygrup-logo.png'))` and passed as a data URI — DomPDF's chroot blocks `public_path()` file system access from the template; (b) `->setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])` activates the bundled DejaVu font which has full Unicode/Turkish coverage; (c) `<th>` content is written as literal uppercase strings — CSS `text-transform:uppercase` breaks the dotted İ glyph in DomPDF's font shaping pass; (d) dates use `Carbon::parse()->translatedFormat('d M Y')` for Turkish month names.
- **Widgets** — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart` are registered in `DashboardPanelProvider`. All widgets use `canView()` checking their `widget_X` permission — consistent post-reform. `TicketStatsOverview` `canView()` only checks `widget_TicketStatsOverview` permission. Data scoping happens via `visibleBy()` in queries, not in `canView()`. `RecentTicketsTable` checks `widget_RecentTicketsTable`; its data query uses `Ticket::scopeVisibleBy()` for row-level scoping. `widget_DailyTaskPerformance` was a Spatie permission orphan (no corresponding widget class) — deleted by the May 2026 reform migration.

## CompanySetupWizard
- Hard-blocks each step until prerequisites pass — see Setup Chain Rules in `app/Services/CLAUDE.md`
- `shield:generate --all` rewrites three policies unless `--ignore-existing-policies` is passed (see project root `CLAUDE.md`)

## Page access exceptions
- `PerformanceDashboard`, `CompanySetupWizard`, `ManageGeneralSettings` → `page_{Class}` (Shield)
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
- **CANCELLED SLA badge is always `gray`** — `TicketResource::slaColor()` (and all surfaces: `RecentTicketsTable`, Relation Managers) explicitly returns `'gray'` for CANCELLED before falling through to the terminal-outcome branch. Note: `TaskStatusEnum::CANCELLED->getColor()` returns `'danger'` (the status badge color); the gray behavior is specific to the SLA badge, not the status badge.
- List row actions: wrap them in `Tables\Actions\ActionGroup::make([...])`
  for the compact three-dot menu, with `ViewAction`, `EditAction`,
  `DeleteAction` in that order. Apply per-action `->visible(...)` gates
  inside the group rather than hiding the whole group

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
- Not Ekle reuses `TicketService::addComment` (legacy comment notification to participants)

## Phase 5 screens
- `Pages/TeamPerformanceReport` (Raporlar): date range (default this month), teams, type filters; summary cards, team / people / type / company tables; CSV export (`ticket.export`). View `filament.pages.team-performance-report` uses inline styles
- `SlaExemptionResource` (Raporlar, `/sla-exemptions`): list only (no create/edit/delete); approvers see all (pending by default, badge = pending count) and get Approve / Reject row actions where `canDecide`; others see their own requests
- `ViewTeamTicket`: "Onayla ve Kapat" asks for an optional 1–5 score; "Diğer İşlemler › SLA Muafiyeti İste" when `canRequest`; SLA panel shows exemption status and the score

## Panel layout (faz-6)
- Legacy-flow resources use `Concerns\BelongsToLegacyFlow` (`canAccess()` = `config('legacy.enabled') && parent::canAccess()`); legacy pages and widgets check the same flag in `canAccess()` / `canView()`
- **Rule: every screen reachable with the switch off shows the team model only** — list columns, filters, infolists and the relation managers under a detail page included. Shared resources that had legacy content branch on `config('legacy.enabled')` and keep the legacy version for the switch-on case (phpunit runs with it on, so legacy tests stay valid; new-model tests set `config(['legacy.enabled' => false])`)
- `EmployeeResource` (switch off): `teamTable()` — teams (`teamLabels()`, supervised ones marked "(Amir)"), requests on them / past SLA (team tickets, `TeamPerformanceService::ACTIVE_STATUSES`), company + team filters; `ViewEmployee::teamInfolist()` — workload now, last 30 days / all time from `TeamPerformanceService::personReport()`, team and priority breakdowns (colour vs. the cycles' `success_threshold` average); relation managers `TeamTicketsRelationManager` (requests held now or before, `visibleInTeamWorkflow`, row → `panelUrl()`) and `TeamMembershipsRelationManager` (member ∪ supervised teams, role Üye / Amir / Amir + Üye). Switch on: the old table, `legacyInfolist()`, `TicketsRelationManager`, `GroupMembershipsRelationManager`
- In a `RepeatableEntry` over arrays, `$record` in child closures is the **page record**, not the row — read the row with `$component->getContainer()->getState()`
- New resources go into one of the groups listed in `DashboardPanelProvider::navigationGroups()`; keep `$navigationSort` in steps of 10
- Dashboard: `Widgets/RequestOverviewStats`, `MyRequestQueue`, `RequestsByStatusChart`, `OpenRequestsByTypeChart` — data scoped per viewer, so they are not Shield-gated
