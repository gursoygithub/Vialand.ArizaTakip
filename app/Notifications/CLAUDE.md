# Notifications Guide

## Channels
- All ticket notifications return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the panel bell renders title / body / icon / action button automatically
- All notification classes implement `ShouldQueue` (except `UserCreated`) — they run on the configured queue: `database` + `queue:work` where set, but **inline under `sync` in production today** (see root CLAUDE.md → Queue)
- Mail is **always sent** for the 7 mail-capable classes below plus the `TicketOnHoldMail` mailable (= "8 targeted events"). Neither `config('notifications.mail_enabled')` nor the per-user `mail_enabled` preference is consulted — both are inert
- FCM push is always **data-only** (no `notification` key in the payload) so the browser's `onMessage` handler fires in the foreground and Filament renders its own toast. All payload goes in the `data` field: `{ title, body, url }`
- Database channel is added in `via()` only when `$user->wantsNotification(type, 'database')` is true. `ALWAYS_ON_DATABASE` (`ticket_assigned`, `ticket_cancelled`, `sla_breach`) cannot be opted out

## Mail Template
All ticket mails (7 classes + `TicketOnHoldMail`) render `resources/views/mail/ticket-event.blade.php`. Variables used by the template:
- `$ticket` — Ticket model (ticket_no, area, subArea, unit, priority, sla_deadline, sla_breached, employee, createdBy)
- `$notifiableName` — recipient display name for greeting (`''` in `TicketOnHoldMail`)
- `$eventTitle` / `$eventDescription` — headline + body text (`ui.notification_*_desc` keys)
- `$headerColor` — hex accent; rendered as a 4px `border-top` (Outlook-compatible, **no gradient**). `$headerColorDark` is still passed by callers but unused by the template
- `$note` — optional note box (e.g. "Eski → Yeni" for reassignment)
- `$priorityStyles` — computed inside the template

## Mute & Recipient Rules
- Mute short-circuits the **whole** notification (bell, FCM **and mail**) on: observer assignment paths, `notifyResolved` (creator), `notifyCancelled`, `notifyReopened`, `notifyParticipants` (via `getTicketParticipants`)
- Self-actions never notify the actor (per-method checks + `notifyParticipants`)

## Preferences page (`Filament/Pages/NotificationPreferences`)
- Rows: `ticket_assigned`, `ticket_participant`, `ticket_closed`, `ticket_reopened`, `ticket_reassigned`, `sla_warning`, `sla_breach` — each with a bell and a mail toggle
- Mail toggles are stored but **never read**; `ticket_closed` matches no notification class; `ticket_resolved` and `ticket_cancelled` are not listed (resolved is therefore always on)
- Accessible to every logged-in user (`canAccess()` = authenticated, no `page_` permission)

## Date Formatting
- Use `translatedFormat('d F Y H:i')` in `mail.ticket-event` for Turkish month names (Carbon locale is `tr`)
- Use `d.m.Y H:i` in any plain-text context — locale-independent

## Team tickets (Phase 4) — `TeamTicketNotification` + `TeamTicketNotifier`
Levels: **action** = bell + mail (+ push where marked), ignores preferences and mutes · **important** = bell + mail, ignores preferences and mutes · **info** = bell only, skipped when the ticket is muted or the `ticket_participant` bell preference is off. Actor is never notified (except the requester's own "talebiniz alındı"). One notice per person per event (strongest level wins). Users without an email get the bell only.

| Event (`TeamTicketEventEnum`) | Recipients |
|---|---|
| Opened | supervisor: action + push · requester: info |
| Assigned | assignee: action + push · requester: info |
| Started | requester: info |
| Held | requester: action + push if `AwaitingRequester`, else info · supervisor: info |
| Resolved | requester: action + push |
| ResolutionRejected ("Çözülmedi") | assignee: action + push · supervisor: info |
| Closed | assignee: info |
| Reopened | assignee (or supervisor if none): action + push · supervisor: info |
| Transferred | new supervisor: action + push · old supervisor, requester: info |
| Rejected / Cancelled | requester: important · assignee: info |
| SlaWarning (80 %, once per cycle) | assignee, or supervisor if unassigned: important |
| SlaBreached (once per cycle) | assignee + supervisor: important |
| SlaEscalated (once per cycle) | users with `ticket.sla.escalation`: important |

Mail uses `mail.ticket-event` with `ticketUrl` (→ `/requests/{id}`) and a "Tür / Ekip" row. "Not Ekle" goes through `TicketCommentService::addComment` (bell only, `TicketCommentNotification`).

## Links
Always link to a ticket with `$ticket->panelUrl()` (→ `/requests/{id}`) in bell actions, mails and FCM pushes. Never build the path by hand — the legacy comment notification once sent team-ticket readers to a 404.
