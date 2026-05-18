# Database Guide

## Migration Naming
Format: `YYYY_MM_DD_HHMMSS_verb_subject.php`
Examples:
- `2026_04_30_000001_rename_tasks_to_tickets_add_columns.php`
- `2026_04_30_000002_create_ticket_status_histories_table.php`

## Column Conventions
- All new columns in **English**
- Foreign keys: `{singular_table}_id` (e.g. `ticket_id`, `user_id`, `area_id`)
- Soft delete: always pair `$table->softDeletes()` with `deleted_by unsignedBigInteger nullable`
- Append-only tables (e.g. status histories): skip `updated_at`, use `$table->timestamp('created_at')`

## Soft Deletes Required
`tickets`, `groups`, `sla_policies`, `employees`, `users`, `areas`, `sub_areas`

## tickets Table — Key Columns Added by Reform
| Column | Type | Notes |
|---|---|---|
| ticket_no | VARCHAR(20) UNIQUE | Auto: TKT-YYYY-NNNNN via Ticket::boot() |
| sla_deadline | DATETIME NULL | Set on create via TicketObserver + SlaService |
| sla_breached | BOOLEAN DEFAULT false | Three writers: TicketObserver::saving, TicketService transitions, CheckSlaBreaches job |
| assigned_at | DATETIME NULL | Set when status → assigned (also stamped by Observer when employee_id is attached) |
| resolved_at | DATETIME NULL | Set when status → resolved |
| closed_at | DATETIME NULL | Set when status → closed/completed |
| closed_by | FK users NULL | User who closed the ticket |
| total_on_hold_minutes | INT DEFAULT 0 | Accumulated paused time for on_hold/resume cycles |

Composite index `tickets_sla_status_idx (sla_breached, status)` backs the
"breached" tab, the navigation badge color, and `Ticket::scopeSlaBreached`.

## ticket_status_histories Table
- Append-only — no `updated_at`, no soft deletes
- Columns: id, ticket_id, from_status, to_status, changed_by (FK users), note (TEXT NULL), created_at

## Notifications & Push
- `ticket_mutes` (`ticket_id`, `user_id`, timestamps; UNIQUE `(ticket_id, user_id)`) — per-user mute toggle on the View page; `Ticket::isMutedBy(User)` short-circuits notification senders
- `fcm_tokens` (`user_id`, `token`, `last_seen_at`) — web-push tokens consumed by `App\Services\FcmService`
- `user_notification_preferences` — per-user channel switches (database / mail / push) keyed by event type

## Never Edit Existing Migrations
Always write a new migration file. Schema changes are additive.

## Schema Quirks (live data)
- `tasks` table renamed to `tickets` (2026_04_30_000001) — backward-compat alias `App\Models\Task extends Ticket`
- `sla_policies.sub_area_id` is **NOT NULL** — SlaService falls back at area+unit+priority level only
- `users.employee_id` is NOT NULL → UserFactory provides a synthetic `Eflag######` value
- `users.created_by` is NOT NULL → UserFactory defaults to `1`
- The `2026_01_24_170253_update_employees_unique_constraint` migration was patched to be
  idempotent (try/catch) so SQLite test runs do not fail on the duplicate index
- Tests use SQLite in-memory; production uses MySQL via `.env`
- `employees.performance_score` and `employees.current_threshold` are **NULLABLE** (`decimal(5,2) NULL`). The original migration added them as `DEFAULT 0`; a follow-up migration (`2026_05_18_*_make_employee_performance_columns_nullable`) backfills all zeros to NULL. **`null` means "no sealed tickets yet"** — not a zero score. UI code must guard `is_null()` before rendering.
