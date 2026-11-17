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
| on_hold_since | DATETIME NULL | Set when status → on_hold; cleared on resume (by `SlaService::extendDeadlineForOnHold`) |
| total_on_hold_minutes | INT UNSIGNED DEFAULT 0 | Accumulated paused time for on_hold/resume cycles |
| reopen_reason | VARCHAR(255) NULL | Optional note on reopen |
| reopened_by | FK users NULL | User who reopened the ticket |
| reopened_at | DATETIME NULL | Timestamp of last reopen |
| sla_warning_sent_at | TIMESTAMP NULL | Last 80% SLA warning; `CheckSlaBreaches` sends at most one per calendar day (`2026_11_17_174453`) |

Composite index `tickets_sla_status_idx (sla_breached, status)` backs the
"breached" tab, the navigation badge color, and `Ticket::scopeSlaBreached`.

## ticket_status_histories Table
- Originally append-only; `updated_at` was added later to support comment editing (`2026_05_01_040549`)
- No soft deletes
- Columns: id, ticket_id, from_status, to_status, changed_by (FK users NULL), note (TEXT NULL), created_at, updated_at (NULLABLE)

## Notifications & Push
- `ticket_mutes` (`ticket_id`, `user_id`, timestamps; UNIQUE `(ticket_id, user_id)`) — per-user mute toggle on the View page; `Ticket::isMutedBy(User)` short-circuits notification senders
- `fcm_tokens` (`user_id`, `token` TEXT, `is_active` BOOLEAN DEFAULT true, `token_hash` VARCHAR(64), timestamps; UNIQUE `(user_id, token_hash)`) — web-push tokens consumed by `App\Services\FcmService`. `token_hash` exists because the full token exceeds MySQL's index length limit.
- `user_notification_preferences` — per-user channel switches (`mail_enabled`, `database_enabled`) keyed by `notification_type` enum. No `push_enabled` column — FCM push follows the `database_enabled` preference.

## Never Edit Existing Migrations
Always write a new migration file. Schema changes are additive.

## Schema Quirks (live data)
- `tasks` table renamed to `tickets` (2026_04_30_000001). There is **no** `App\Models\Task` alias any more — use `Ticket`
- `sla_policies.sub_area_id` is **NULLABLE** — allows area-wide SLA defaults (sub_area IS NULL); `SlaService::resolvePolicy` L2 fallback matches these rows
- `users.employee_id` is NULLABLE and holds `employees.employee_id` (external personnel number / `UNIQUE_ID`), **not** `employees.id`. LDAP-synced users get a value; manually-created admins may have NULL. `User::employee()` joins on `email`, not on this column
- `users.created_by` is NULLABLE — system-created users (seeders, LDAP sync) may have NULL
- The `2026_01_24_170253_update_employees_unique_constraint` migration was patched to be
  idempotent (try/catch) so SQLite test runs do not fail on the duplicate index
- Tests use SQLite in-memory; production uses MySQL via `.env`
- **Schema dump**: `database/schema/mysql-schema.sql` (squashed 2026-05-18, 39 tables). The migration files are still in `database/migrations/`; on MySQL `migrate` loads the dump first and then runs only migrations missing from its `migrations` table. SQLite (tests) ignores the dump and runs the migration files
- Tables referenced by leftover code but **absent** from the schema: `reports`, `daily_reports` (see root CLAUDE.md "Dead / Leftover Code")
- External connections: `sqlsrv2` (employee / technician sync, `_TGRY_PERSONEL`), `sqlsrv` (used only by the dead `DailyReportService`)
- `employees.performance_score` and `employees.current_threshold` are **NULLABLE** (`decimal(5,2) NULL`). The original migration added them as `DEFAULT 0`; a follow-up migration (`2026_05_18_*_make_employee_performance_columns_nullable`) backfills all zeros to NULL. **`null` means "no sealed tickets yet"** — not a zero score. UI code must guard `is_null()` before rendering.

## Request model tables (Phase 1, `2026_11_17_2000xx`)
| Table | Key columns |
|---|---|
| ticket_types | name, description, icon, color, location_requirement (hidden/optional/required), form_fields JSON, is_active, sort_order, audit cols, soft deletes |
| work_calendars | name, is_24_7, working_hours JSON (ISO weekday → {start,end}), observes_holidays, is_active, audit cols, soft deletes |
| holidays | date (UNIQUE, Y-m-d), name |
| teams | ticket_type_id FK, name, supervisor_employee_id FK employees, work_calendar_id FK NULL (nullOnDelete), allow_self_assign, is_active, audit cols, soft deletes |
| team_members | team_id FK cascade, employee_id FK cascade, UNIQUE (team_id, employee_id) |
| team_sla_policies | team_id FK cascade, priority (1–4), response_minutes, resolution_minutes, success_threshold; UNIQUE (team_id, priority) |
| tickets (+) | ticket_type_id FK NULL, team_id FK NULL, custom_fields JSON NULL |
Settings migration `database/settings/2026_11_17_200006`: `general.auto_close_days` = 3, `general.reopen_window_days` = 7.

## SLA engine tables (Phase 2, `2026_11_17_2100xx`)
| Table | Key columns |
|---|---|
| ticket_sla_cycles | ticket_id, cycle_no (UNIQUE per ticket), team_id, priority, response/resolution minutes + success_threshold + work_calendar_id (snapshot), started_at, response_due_at, resolution_due_at, responded_at, resolved_at, resolution_rejections, paused_since / pause_kind (hold, awaiting_confirmation) / paused_minutes, response_breached, resolution_breached, breached_at, breach_attribution (person/handover/dispatch), breach_employee_id, warning_sent_at, breach_notified_at, outcome (on_time/breached/cancelled/rejected/transferred), ended_at |
| ticket_assignments | ticket_id, ticket_sla_cycle_id, employee_id, assigned_by, self_assigned, started_at, ended_at, end_reason, remaining_ratio_at_start |
| tickets (+) | hold_reason, closure_reason, auto_close_at, reopen_until; `area_id`, `sub_area_id`, `unit_id` are now NULLABLE (FKs kept) |
`TaskStatusEnum::REJECTED = 17` added (terminal, excluded from performance).

Phase 4: `ticket_sla_cycles.escalated_at`; settings `general.sla_escalation_hours` = 4.

Phase 5 (`2026_11_17_230001`): `ticket_sla_cycles.satisfaction_score`, `satisfaction_comment`, `rated_at`, `exempted`; table `sla_exemptions` (ticket_sla_cycle_id, ticket_id, requested_by, reason, note, status pending/approved/rejected, decided_by, decided_at, decision_note).
