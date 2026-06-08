# Filament Guide

## Layout
- `Resources/` — one Resource per model (Area, Company, Employee, Group, SlaPolicy, SubArea, Subcontractor, SubcontractorEmployee, Ticket, Unit, User)
- `Resources/{Name}Resource/Pages/` — List, Create, Edit, View; **Ticket** also has the View page hosting the timeline + transition actions
- `Resources/{Name}Resource/RelationManagers/` — RelationManagers for nested relationships (e.g. `UserResource/CompanyAccessRelationManager`)
- `Pages/` — custom pages: `CompanySetupWizard`, `PerformanceDashboard`, `NotificationPreferences`, `ManageGeneralSettings`
- `Pages/Auth/` — `LoginPage` (custom LDAP login) and `EditProfile` (profile edit page)
- `Widgets/` — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart`
- `Exports/` — Filament export classes (`TaskExporter.php` — legacy name, exports tickets)

## Single Responsibility
- One Resource per model; one Widget per chart/metric
- Business logic stays in `app/Services/` — never inline in Resources

## Resource Scope (non-Ticket)
All non-Ticket Resources use the `ScopedByVisibility` trait
(`app/Filament/Concerns/ScopedByVisibility`). Provides:
- Default `getEloquentQuery()` scoped to `created_by = auth()->id()`
- Override with `protected static string $viewAllPermission = 'view_all_X'` to
  allow users with that permission to see all records
- `super_admin` bypasses unconditionally via Spatie's Gate::before
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
- Filters (in `->filters([...])` on the list table):
  - `area_id`, `status`, `priority`, `employee_id` — all `->multiple()` so users can slice across multiple values at once. `area_id` and `employee_id` are relationship-driven (`->relationship(...)` + `->searchable()->preload()`); `status` and `priority` build options from their respective enum cases.
  - `created_at` — custom `Filter::make` with `from`/`to` `DatePicker`s, `whereDate('created_at', '>=', from)` / `whereDate('created_at', '<=', to)`. Day-granularity, each bound independent.
  - `sla_status` — column-backed only: options are `breached` (→ `Ticket::scopeSlaBreached`) and `no_sla` (→ `whereNull('sla_deadline')`). The previous `on_time` / `warning` options were dropped because their raw `TIMESTAMPDIFF` SQL was MySQL-only (broken on the SQLite test path) and ignored the on-hold pause + `total_on_hold_minutes` credit, leaving them inconsistent with the per-row live label. **Use `Ticket::getSlaStatusLabel()` for live elapsed/warning display per row; this filter is for the persisted breach/no-policy axis only.**

### View page (`Pages/ViewTicket`)
- Lifecycle strip (`filament.partials.ticket-lifecycle-strip`) at top: 5 numbered steps — Açıldı / Atandı / İşleme Alındı / Çözüldü / Kapatıldı. Step "done" sources: `created_at`, `assigned_at`, first IN_PROGRESS `ticket_status_histories` row **scoped to `created_at >= assigned_at`** (the scope makes the strip reflect the *current* assignment cycle after a reopen), `resolved_at`, `closed_at`
- **Talep Geçmişi** (timeline) uses `ViewEntry` (not `TextEntry`) — TextEntry's prose typography clamps the partial to ~600px; ViewEntry renders the partial directly for true full width
- Timeline renderer (`renderTimeline`) handles 4 entry shapes: creation (from=null), transition (from!=to), reassignment (from==to AND note starts with `REASSIGN_NOTE_PREFIX`), comment (from==to, plain note). Comment cards expose Edit/Delete chips inside the 10-min window via `wire:click="mountAction('editComment'|'deleteComment', { history_id: N })"` mounted by `editCommentAction` / `deleteCommentAction`
- **ON_HOLD banner** — when `$record->status === ON_HOLD`, a `Section` containing a `ViewEntry` referencing `filament.infolists.components.on-hold-banner` renders as the first infolist item. The `since` and `duration` variables are computed at `infolist()` build time (same pattern as `$inProgressAt`): `$onHoldSince = $record->on_hold_since?->translatedFormat('d M Y H:i')` and `$onHoldDuration = DurationFormatter::minutes((int) $record->on_hold_since->diffInMinutes(now()))`. The banner Blade partial uses inline hex styles (no Tailwind tokens) because Tailwind JIT does not scan partials in `resources/views/filament/infolists/`.
- Header action visibility (source-of-truth — see top-of-file comment in `ViewTicket.php`):
  - `İşleme Al` / `Çözüldü` / **`Beklet`** (label is a verb, not the noun "Beklemede") → assigned employee's user OR `super_admin`
  - `İptal Et` / `Kapat` → creator OR `super_admin`
  - `Yeniden Aç` (CLOSED/RESOLVED/COMPLETED → **ASSIGNED**) → creator OR `super_admin` (`TicketPolicy::reopen`). Rendered by `buildTransitionActions` when `$to === ASSIGNED && $from ∈ terminal-set`; the OPEN → ASSIGNED case is still skipped in favor of the dedicated `Ata` action. Server-side gate enforced via `->before(fn () => Gate::authorize('reopen', $ticket))` so a forged mountAction call is rejected with 403 even if `visible()` is bypassed. The legacy `ticket.reopen` Spatie permission is no longer consulted
  - `Ata` / `Yeniden Ata` → `ticket.assign` AND **(creator OR current assignee's user OR group supervisor)**, not terminal (reopen is a separate button)
  - `Düzenle` / `Sil` → creator OR `super_admin`
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
- `shield:generate --all` rewrites three policies; restore them from git after running (warning is in the project root `CLAUDE.md`)

## DashboardPanelProvider
- `->databaseNotifications()` enabled — bell auto-renders our `FilamentNotification` payloads
- `->plugins([...])` registers Shield; FCM init JS (`resources/views/filament/fcm-init.blade.php`) is registered via `->renderHook(PanelsRenderHook::BODY_END, ...)`, not as a plugin

## Conventions
- Field labels via `__('ui.*')` keys — `ui.task_date` resolves to "Arıza Tarihi"
- Section icons use `heroicon-o-*` outlines
- For per-row SLA badges, prefer `Ticket::getSlaStatusLabel()` and the live `now()` comparison; the `sla_breached` column is for **filtering**, not display
- **CANCELLED SLA badge is always `gray`** — `TicketResource::getRowSlaColor()` (and all surfaces: `RecentTicketsTable`, Relation Managers) explicitly returns `'gray'` for CANCELLED before falling through to the terminal-outcome branch. Note: `TaskStatusEnum::CANCELLED->getColor()` returns `'danger'` (the status badge color); the gray behavior is specific to the SLA badge, not the status badge.
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
