# Services Guide

## Rule
All business logic lives in `app/Services/`. Never put business logic in Filament Resources,
Widgets, or Observers (observers trigger services, they don't contain logic).

## SlaService (`App\Services\SlaService`)
- `resolvePolicy(int $areaId, ?int $subAreaId, int $unitId, string|int $priority): ?SlaPolicy` — lookup order: (area + subArea + unit + priority) → fallback (area + unit + priority)
- `calculateDeadline(SlaPolicy $policy, Carbon $from): Carbon` — `$from->addMinutes($policy->deadline_minutes)`
- `getRemainingMinutes(Ticket $ticket): ?int` — minutes until deadline, paused while `on_hold` (uses `on_hold_since`)
- `getElapsedPercentage(Ticket $ticket): ?float` — `0.0`–`1.0+`, used by widgets for the >50%/<50% split
- `checkBreach(Ticket $ticket): bool` — true when deadline is in the past and ticket is not closed

## TicketService (`App\Services\TicketService`)
- `transition(Ticket, TaskStatusEnum $to, User $by, ?string $note = null): Ticket` — validates against transition matrix, stamps timestamps, writes `TicketStatusHistory`, dispatches `TicketStatusChanged` event, fans out notifications via `dispatchTransitionNotifications`
- `reassign(Ticket, int $employeeId, User $by, ?string $note = null): Ticket` — sets `TicketObserver::$skipReassignNotification` around the update to avoid double-fire; auto-clears mute for new assignee BEFORE update; notifies creator (unless creator is actor/new assignee)
- `addComment(Ticket, User, string $note): TicketStatusHistory` — from==to row + `TicketCommentNotification` to participants
- `notifyPriorityChange(Ticket, TaskPriorityEnum $old, TaskPriorityEnum $new, User $actor): void` — bell + FCM only (uses `TicketCommentNotification` whose `via()` returns `['database']`); never sends mail. Body: `"{actor} önceliği {old} → {new} olarak değiştirdi"`
- `canEditComment(TicketStatusHistory, User): bool` / `updateComment(...)` / `deleteComment(...)` — author + 10-min window + must be from==to AND not a `REASSIGN_NOTE_PREFIX` row. `updateComment` also fans out a "Not güncellendi" notification via notifyParticipants
- `allowedNextStatuses(?TaskStatusEnum $from): array` — used by `ViewTicket::buildTransitionActions` to render one button per allowed next status
- `markResolved` / `markClosed` / `markCancelled` set the **terminal SLA outcome** (`sla_breached` true/false based on `now()` vs `sla_deadline`); `markCancelled` clears breach flag (cancelled excluded from SLA)
- **Reopen path** (any of CLOSED/COMPLETED/RESOLVED/CANCELLED → **ASSIGNED**): clears `closed_at`/`closed_by`/`resolved_at`/`assigned_at`, **rebases `sla_deadline` from now() + policy.deadline_minutes + total_on_hold_minutes**, unconditionally resets `sla_breached = false` (saving() may flip it back if rebase failed). The block keys on `$toStatus === ASSIGNED` AND `$from` ∈ terminal-set; reopens land back on the assignee, not on IN_PROGRESS. `assigned_at = null` lets `TicketObserver::saving` re-stamp it to `now()` (since `employee_id` is preserved across reopen) — this scopes the lifecycle strip's "İşleme Alındı" filter (`created_at >= assigned_at`) to the new cycle
- Transition map (from → allowed to):
  - `open → [assigned, in_progress, cancelled]`
  - `assigned → [in_progress, on_hold, cancelled]`
  - `in_progress → [resolved, on_hold, cancelled]`
  - `on_hold → [in_progress, cancelled]`
  - `resolved → [closed, assigned]` (reopen target is ASSIGNED, not IN_PROGRESS)
  - `closed → [assigned]` (reopen — permission gated separately by `ticket.reopen`)
  - `cancelled → []` (terminal — no path back; reopen-recalc still resets state if matrix opens later)

### dispatchTransitionNotifications — targeted recipient rules
`dispatchTransitionNotifications` was refactored to send to narrower recipient sets for key events:
- **OPEN → ASSIGNED** → `TicketAssignedNotification` to assignee only (mail + panel + FCM)
- **terminal → ASSIGNED (reopen)** → `TicketReopenedNotification` to assignee + creator (mail + panel + FCM); via `notifyReopened()`
- **→ RESOLVED** → `TicketResolvedNotification` to creator only (mail + panel + FCM); via `notifyResolved()`
- **→ CANCELLED** → `TicketCancelledNotification` to current assignee only (mail + panel + FCM); via `notifyCancelled()`
- **all other transitions** → `TicketStatusChangedNotification` to full participant set (database-only bell + FCM)

Private helpers: `notifyResolved(Ticket, User)`, `notifyCancelled(Ticket, User)`, `notifyReopened(Ticket, User)` — all check mute, skip actor, then notify and fire FCM directly.

## FcmService (`App\Services\FcmService`)
- `sendToUser(User, string $title, string $body, ?string $url): void` / `sendToUsers(Collection, …)` — fans out to each user's `fcm_tokens` rows
- **Data-only messages** — no `notification` key is sent to FCM; all payload is in the `data` field (`title`, `body`, `url`). This ensures the browser's `onMessage` handler fires in the foreground so Filament can render its own toast instead of the OS notification system intercepting the message.
- Used by `TicketService` notification helpers (`notifyResolved`, `notifyCancelled`, `notifyReopened`), `notifyAssignee`, `notifyCreatorOfReassignment`, `CheckSlaBreaches`, and the comment / priority-change paths
- Tokens stored in `fcm_tokens` (`user_id`, `token`, `last_seen_at`)

## PerformanceService (`App\Services\PerformanceService`)
- `getStats(User $user, Carbon $from, Carbon $to, ?User $viewer = null): array` — per-user stats. Resolves User → Employee by email, scopes by `Ticket::scopeVisibleBy($viewer)` AND `whereBetween('resolved_at', [$from, $to])`. Cancelled tickets excluded. **Date filter uses `resolved_at`, not `created_at`** — the window selects tickets resolved within the period, not tickets opened then.
- `getTeamStats(int $areaId, Carbon $from, Carbon $to): Collection` — per-person stats for every employee in the area's groups (deduped). N+1 by design (one `getStats` query per employee). Returns `avg_resolution_active_minutes` (net resolution time excluding on-hold minutes: `avg_resolution_minutes - avg(total_on_hold_minutes)`) alongside the other `aggregate()` keys.
- `getOverview(Carbon $from, Carbon $to, ?User $viewer = null, ?int $areaId = null): array` — dashboard headline. The optional `$areaId` narrows the ticket scope to a single area. Returns the full `aggregate()` shape PLUS `priority_breakdown` (per-priority [label, total, closed_on_time, breached, compliance_rate], cancelled excluded, only priorities with total>0), `reopen_count` (rows in `ticket_status_histories` with `from_status IN [RESOLVED,CLOSED] AND to_status = ASSIGNED`, scoped to visible tickets, dated within `[$from,$to]`), and `reopen_rate` (`reopen_count / total_assigned * 100`).
- `getRegionBreakdown(Carbon $from, Carbon $to, ?User $viewer = null, ?int $areaId = null): Collection` — per-area roll-up. The optional `$areaId` narrows to a single area (returns a single-row collection). **Excludes CANCELLED** so totals match `aggregate()`'s per-person numbers.
- `aggregate()` (private) emits these metric keys per cohort:
  - `total_assigned`, `closed_on_time`, `closed_breached` (legacy name — same as total_breached), `total_breached` (canonical headline; same value as `closed_breached`, both count `sla_breached=true` after cancelled-rejection), `at_risk` (active tickets with `sla_deadline ≤ now()+2h`, not yet flipped, `status NOT IN [RESOLVED,CLOSED,ON_HOLD]`), `currently_open`, `currently_on_hold`, `avg_resolution_minutes`, `sla_compliance_rate`, `avg_response_time_minutes`.
  - `sla_compliance_rate` **excludes tickets with no SLA policy** (`sla_deadline IS NULL`) from both numerator and denominator — tickets without SLA should not dilute the compliance rate.
  - Plus 7 backward-compat aliases: `total`, `closed`, `on_time`, `breach_count`, `compliance_rate`, `open`, `breached`.
- Dashboard blade (`resources/views/filament/pages/performance-dashboard.blade.php`) consumes `total_breached` (card "Toplam İhlal"), `priority_breakdown` (compact table), `reopen_rate` + `reopen_count` (card "Yeniden Açılma" — green <5%, orange <15%, red ≥15%), `at_risk` (card "Risk Altında" — green=0, orange>0, red>5), and mounts `\App\Filament\Widgets\SlaComplianceTrendChart` via `@livewire(...)` (the chart's window is hard-coded to the last 30 days and does NOT honour the page's date filter).

## Repository Pattern
- Contracts: `App\Repositories\Contracts\TicketRepositoryInterface`
- Implementations: `App\Repositories\TicketRepository`
- Bound in `AppServiceProvider::register()`

## Async / Scheduled Work
- `App\Jobs\CheckSlaBreaches` — see `app/Jobs/CLAUDE.md`. The job is one of three writers of the `sla_breached` column; the other two are `TicketObserver::saving` and the `TicketService` terminal-state markers
- `App\Observers\TicketObserver` — registered in AppServiceProvider; see `app/Models/CLAUDE.md` for the saving-hook flip rule

## Notifications (Filament-compatible)
- All ticket notifications return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the panel bell renders title/body/icon/action
- Mail channel is opt-in via `config/notifications.php` (`mail_enabled`, default false)
- Per-user channel preferences via `UserNotificationPreference` (`database` / `mail` / `push` per event type) — see `app/Notifications/CLAUDE.md`

## Setup Chain Rules

These rules are NON-NEGOTIABLE. Every feature that touches setup data must
enforce this chain:

1. **Company must exist before anything else**
   → companies populated automatically via `employee:sync`

2. **Area (bölge) requires a company**
   → `Area::company_id` is mandatory

3. **SubArea (lokasyon) requires an area**
   → `SubArea::area_id` is mandatory

3b. **SubArea (lokasyon) — at least one required per area**
   → Areas with 0 sub_areas block ticket creation (no location to select)
   → `CompanySetupWizard` hard-blocks step 2 until all areas have at least
     one sub_area

4. **SLA Policy requires: area + unit + priority** (at minimum)
   → Cannot create SLA without at least one area
   → `SlaService::resolvePolicy()` uses 3-level fallback but always needs
     `area_id` as the starting point

5. **Group requires: area + unit + supervisor (employee_id)**
   → Cannot create group without area, unit, or supervisor

5b. **Every group must have at least one member**
   → A group with zero members can't carry tickets

5c. **`CompanySetupWizard` hard-blocks step 4 on all three conditions**
   → No groups for company / group without supervisor / group with zero
     members all halt step 4 with a danger notification (no soft path)

6. **Ticket requires: area + SLA policy** (resolved automatically)
   → `TicketObserver` resolves SLA on `creating`
   → If no SLA found → ticket gets `NULL sla_deadline` (no SLA tracking)
   → **`CreateTicket::beforeCreate()` adds a server-side guard**: if
     `SlaService::resolvePolicy()` returns null for the submitted
     area/unit/priority combination, it throws a `ValidationException`
     on `data.unit_id` before the Eloquent record is created. This
     prevents tickets from silently landing with no SLA.

7. **User visibility requires: employee → company_id**
   → If employee has no `company_id` → fallback to own tickets only

Breaking this chain at any point causes silent failures. Always validate
prerequisites before allowing creation.
