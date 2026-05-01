# Notifications Guide

## Channels
- All ticket notifications return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the panel bell renders title / body / icon / action button automatically
- Mail is opt-in globally via `config/notifications.php` → `mail_enabled`; FCM push is opt-in per-user via `UserNotificationPreference`
- Database is the default channel for every event; mail and push only fire when both the global flag and the user's preference allow them

## Classes
- `TicketAssignedNotification` — fires from `TicketObserver::updated` (direct reassign) and `TicketService::transition` (status → assigned); also pushes via `FcmService` when the recipient prefers push
- `TicketReassignedNotification` — old assignee gets a "you've been replaced" alert
- `TicketStatusChangedNotification` — fired from the status-change event for participants other than the actor
- `TicketCommentNotification` — fired when a comment is added via `TicketService::addComment`
- `TicketClosedNotification` — sent at close; body shows on-time vs. breach (derived from `resolved_at` / `closed_at` vs `sla_deadline`, not from the persisted column)
- `TicketCancelledNotification` / `TicketReopenedNotification` — terminal-state alerts
- `SlaWarningNotification` — 80% time elapsed, sent by `CheckSlaBreaches`
- `SlaBreachedNotification` — sent by `CheckSlaBreaches` when the deadline is crossed
- `UserCreated` — onboarding email for newly-created users

## Mute & Recipient Rules
- Every sender call passes through `Ticket::isMutedBy($user)` (composite-unique on `ticket_mutes`); muted users get neither database nor push, but mail still respects the global flag
- Canonical SLA recipients are assignee + group supervisor — see `CheckSlaBreaches::slaRecipients` (no admin fallback by design; the dashboard widget surfaces breaches for admins)
- Self-actions never notify the actor (filtered in `TicketStatusChangedNotification` recipient resolver)

## Daily De-dupe (in CheckSlaBreaches)
- `alreadySentToday(Ticket, NotificationClass)` reads today's `notifications` rows and inspects `data.actions[*].url` for `/tickets/{id}` to decide whether the same alert already fired
- This is portable across MySQL/SQLite (no JSON-extract SQL) and survives the Observer flipping `sla_breached` mid-window

## Date Formatting
- Use numeric `d.m.Y H:i` in mail templates — locale-independent, no translation surprises
- For UI-bound bodies (database channel rendered through Filament), use `Carbon::translatedFormat('d F Y H:i')` for Turkish month names
