# Filament Guide

## Layout
- `Resources/` — one Resource per model (Area, Company, Employee, Group, SlaPolicy, SubArea, Subcontractor, SubcontractorEmployee, Ticket, Unit, User)
- `Resources/{Name}Resource/Pages/` — List, Create, Edit, View; **Ticket** also has the View page hosting the timeline + transition actions
- `Resources/{Name}Resource/RelationManagers/` — RelationManagers for nested relationships (e.g. `UserResource/CompanyAccessRelationManager`)
- `Pages/` — custom pages: `CompanySetupWizard`, `PerformanceDashboard`, `NotificationPreferences`, `ManageGeneralSettings`
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

### Edit page (`Pages/EditTicket`)
- **Hard 403 at `mount()`** — only ticket creator OR `super_admin` may reach it; `ticket.view.*` are read-only scopes and do NOT grant write access
- Terminal-state lock: `TicketPolicy::update` AND `TicketPolicy::delete` both return `false` for RESOLVED/CLOSED/CANCELLED **including super_admin** — reopen must go through `TicketService::transition` (CLOSED/RESOLVED → ASSIGNED), never via the Edit page; the same rule blocks deletion of finalised tickets (reopen first, then delete)
- `beforeSave` snapshots the original priority; `afterSave` calls `TicketService::notifyPriorityChange(...)` if priority changed (DB+FCM only, no mail)
- Priority change also triggers `TicketObserver::saving` to rebase `sla_deadline = now() + policy.deadline_minutes + total_on_hold_minutes` (non-terminal only)
- Redirects to `view` page after save

## PerformanceDashboard (`Pages/PerformanceDashboard`)

- `canAccess()` checks `report.view` — **this differs from Shield's `page_PerformanceDashboard`**. Roles granted the page via the Shield UI panel will NOT gain access unless they also have `report.view`. Align these or document the discrepancy before granting the manager role access.
- **Export** — `{{ $this->exportAction }}` (Filament Action accessor) does not render on custom pages. Instead, a plain `<button>` dispatches `open-modal` to an `x-filament::modal` containing the export form. The Livewire properties `$exportFormat` (string, default `'pdf'`) and `$exportSections` (array, default `['kpi','priority','region','team']`) back the modal form. `submitExport()` is the Livewire action called by the modal footer button — it returns a `StreamedResponse` directly (Livewire 3 picks up returned download responses from actions).
- **Active preset highlighting** — `$activePreset` (nullable string Livewire property) stores the last clicked preset key (`'this_week'`, `'this_month'`, etc.). `setDateRange(string $preset)` sets it; `updatedDateFrom()` / `updatedDateTo()` clear it to `null` when the user manually changes a date input. Preset buttons use inline `style=` hex colors to avoid Tailwind purge issues in the custom Blade view.
- **PDF export** — uses `barryvdh/laravel-dompdf`. Key decisions: (a) logo is base64-encoded at render time via `file_get_contents(public_path('img/gursoygrup-logo.png'))` and passed as a data URI — DomPDF's chroot blocks `public_path()` file system access from the template; (b) `->setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])` activates the bundled DejaVu font which has full Unicode/Turkish coverage; (c) `<th>` content is written as literal uppercase strings — CSS `text-transform:uppercase` breaks the dotted İ glyph in DomPDF's font shaping pass; (d) dates use `Carbon::parse()->translatedFormat('d M Y')` for Turkish month names.
- **Widgets** — `TicketStatsOverview`, `RecentTicketsTable`, `SlaComplianceTrendChart`, `TicketsByPriorityChart`, `TicketsByStatusChart` are registered in `DashboardPanelProvider`. Only `TicketStatsOverview` has a `canView()` override (checks `ticket.view.all` OR `ticket.view.group`). `RecentTicketsTable` renders for any authenticated user regardless of `widget_RecentTicketsTable`. `widget_DailyTaskPerformance` is a Spatie permission orphan — no corresponding widget class exists; it can be safely deleted from the DB.

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
