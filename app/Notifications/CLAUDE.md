# Notifications Guide

## Channels
- All ticket notifications return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the panel bell renders title / body / icon / action button automatically
- Mail is **always sent** for the 8 targeted events below — `mail_enabled` config is intentionally bypassed in their `via()` methods
- FCM push is always **data-only** (no `notification` key in the payload) so the browser's `onMessage` handler fires in the foreground and Filament renders its own toast. All payload goes in the `data` field: `{ title, body, url }`
- Database is the default channel for every event; the per-user `UserNotificationPreference` still controls whether the bell (database) fires per type

## Targeted Event Rules (mail + panel + FCM)

| Event | Recipient(s) | Class |
|---|---|---|
| Ticket created/assigned (initial) | Assignee only | `TicketAssignedNotification` |
| Reassigned (devredildi) | New assignee (`TicketAssignedNotification`) + creator (`TicketReassignedNotification`) | two classes |
| Resolved (çözüldü) | Creator only | `TicketResolvedNotification` |
| Cancelled (iptal edildi) | Current assignee only | `TicketCancelledNotification` |
| Reopened (yeniden açıldı) | Assignee (if any) + creator | `TicketReopenedNotification` |
| SLA Warning (80% elapsed) | Assignee + creator | `SlaWarningNotification` |
| SLA Breached | Assignee + creator | `SlaBreachedNotification` |

For all 8 rules: mail fires unconditionally (ignores `mail_enabled` config). Muted users (ticket_mutes) are excluded from database + FCM but still receive mail.

## Mail Template
All 8 events use `resources/views/mail/ticket-event.blade.php`. Variables:
- `$ticket` — Ticket model (provides ticket_no, area, subArea, unit, priority, sla_deadline, sla_breached, employee, createdBy)
- `$notifiableName` — recipient display name for greeting
- `$eventTitle` — e.g. "Talep Çözüldü", "Talep Devredildi"
- `$eventDescription` — body explanation text
- `$headerColor` / `$headerColorDark` — hex colors for the gradient header
- `$note` — optional note box (e.g. "Eski → Yeni" for reassignment)

Template always renders: ticket_no, status label, priority badge, area/location/unit, assignee name, creator name, SLA deadline (`translatedFormat('d F Y H:i')` → Turkish month names), SLA breach indicator, and a "Talebi Görüntüle" CTA button.

## Classes
- `TicketAssignedNotification` — fires from `TicketObserver::updated` (direct reassign), `TicketObserver::created` (created already-assigned), and `TicketService::dispatchTransitionNotifications` (OPEN → ASSIGNED); each path also fires an FCM data-only push; mail always sent
- `TicketReassignedNotification` — sent to the **creator** by `TicketService::notifyCreatorOfReassignment` when someone else reassigns (skipped if creator is actor or new assignee); mail always sent
- `TicketResolvedNotification` — sent to **creator only** by `TicketService::notifyResolved` when → RESOLVED; mail always sent
- `TicketCancelledNotification` — sent to **current assignee only** by `TicketService::notifyCancelled` when → CANCELLED; mail always sent
- `TicketReopenedNotification` — sent to **assignee + creator** by `TicketService::notifyReopened` on reopen (terminal → ASSIGNED); mail always sent
- `TicketStatusChangedNotification` — database-only bell to full participant set for all other transitions (ON_HOLD, IN_PROGRESS, CLOSED, etc.); no mail
- `TicketCommentNotification` — used by `TicketService::addComment`, `updateComment` (body: `"Not güncellendi: ..."`), AND `notifyPriorityChange`; `via()` returns `['database']` only — fans out to bell + FCM, **never mail**
- `TicketClosedNotification` — **dead code**: the class exists and is imported in `TicketObserver`, but is never instantiated anywhere. The `→ CLOSED` transition falls through to `TicketStatusChangedNotification` (database-only) via `notifyParticipants`. The import in `TicketObserver.php` is an orphan.
- `SlaWarningNotification` — 80% time elapsed; sent by `CheckSlaBreaches` to assignee + creator; mail always sent
- `SlaBreachedNotification` — sent by `CheckSlaBreaches` when deadline crossed; to assignee + creator; mail always sent
- `UserCreated` — onboarding email for newly-created users; mail only

## Mute & Recipient Rules
- Targeted notifications (RULES 1–5): check `Ticket::isMutedBy($user)` before sending database + FCM. Mail still goes through since these are high-priority events.
- `notifyParticipants` path (all other transitions): `getTicketParticipants` already strips muted users
- Self-actions never notify the actor (enforced per-method and via `notifyParticipants`)

## Daily De-dupe (in CheckSlaBreaches)
- `alreadySentToday(Ticket, NotificationClass)` reads today's `notifications` rows and inspects `data.actions[*].url` for `/tickets/{id}` to decide whether the same alert already fired
- Portable across MySQL/SQLite (no JSON-extract SQL)

## Date Formatting
- Use `translatedFormat('d F Y H:i')` in `mail.ticket-event` for Turkish month names (Carbon locale is `tr`)
- Use `d.m.Y H:i` in any plain-text context — locale-independent
