# 🎫 Ticket System Reform Document
> Reference document for Claude Code — read this before touching any code.

---

## 1. What This System Is

This is a **field service ticket management** platform. Technical teams use it to report faults, manage work orders, and handle maintenance requests.

- **Auth:** LDAP (Active Directory integration)
- **Authorization:** Laravel Shield (Spatie Permission based)
- **First login:** User automatically gets the `default` role → restricted access
- **Stack:** Laravel + Livewire/Blade, MySQL

---

## 2. Actors & Roles

| Role | Capabilities |
|------|-------------|
| `super_admin` | Everything |
| `admin` | Define SLAs, manage groups, assign user roles |
| `supervisor` | Manage tickets in their region, assign technicians |
| `technician` | Create tickets, update tickets assigned to them |
| `viewer` | Read-only access |
| `default` | First-login LDAP user — cannot do anything, awaits role assignment |

---

## 3. Existing Entities (current naming)

### SLA Policy
- `bolge_id` → Region
- `lokasyon_id` → Location
- `teknik_birim_id` → Technical Unit
- `oncelik` → low | medium | high | urgent
- `cozum_suresi_dakika` → integer (resolution time in minutes)
- `basari_esigi_yuzdesi` → integer (success threshold %, default 80)

### Group
- `isim` → name
- `sirket_id` → company FK
- `bolge_id` → region FK
- `birim_id` → unit FK
- `amir_id` → supervisor (User FK)
- `status`

### Task (→ to be renamed `Ticket`)
- `tip` → type
- `bolge_id`, `lokasyon_id`
- `ariza_tarihi` → fault date
- `oncelik` → priority, `durum` → status
- `birim_id`, `grup_id`, `ilgili_kisi_id`
- `aciklama` → description
- `resimler[]` → images
- `sla_politika_id` (implicitly resolved from region + unit)

---

## 4. Current Workflow (AS-IS)

```
LDAP Login
    ↓
Default role assigned (Shield)
    ↓
Admin assigns proper role
    ↓
User added to a group (group has a region)
    ↓
Ticket created:
  - Select region → SLA auto-loads
  - Fill location, unit, priority, date
  - Resolution time comes from SLA
    ↓
Ticket assigned → technician works
    ↓
Ticket closed → SLA compliance calculated
```

---

## 5. Reform Goals

### 5.1 Ticket Lifecycle (TO-BE)

```
open → assigned → in_progress → resolved → closed
                ↘ on_hold ↗
                ↘ cancelled ↗
```

Every status change:
- Logs a row in `ticket_status_histories`
- Records a timestamp (for SLA calculation)
- Responsible person may change

### 5.2 SLA Tracking

When a ticket is created, SLA is **snapshotted** and written to the `tickets` table:
- `sla_deadline` = `created_at` + `resolution_minutes`
- `sla_breached` = boolean (set to true once deadline passes)
- Remaining time shown as countdown in all ticket lists

### 5.3 Performance Tracking

For each user (technician / supervisor):

| Metric | Calculation |
|--------|------------|
| Total assigned tickets | COUNT |
| Closed on time | `closed_at` < `sla_deadline` |
| SLA compliance rate | on_time / total × 100 |
| Average resolution time | AVG(closed_at - assigned_at) |
| Total time on hold | SUM of on_hold minutes |

Dashboard must show:
- Per-person performance card
- Region / unit summary
- Date range picker (week / month / year)
- Export (Excel / PDF)

### 5.4 Permission Matrix (Shield)

```
ticket.create        → technician, supervisor, admin
ticket.view.own      → technician (only their own)
ticket.view.group    → supervisor (their group's tickets)
ticket.view.all      → admin, super_admin
ticket.assign        → supervisor, admin
ticket.close         → supervisor, admin
ticket.delete        → admin, super_admin

sla.manage           → admin, super_admin
group.manage         → admin, super_admin
user.role.assign     → admin, super_admin
report.view          → supervisor, admin, super_admin
```

### 5.5 Notification System

- SLA deadline approaching (e.g. 80% of time elapsed) → alert assigned person + supervisor
- Ticket assigned → notify technician
- Ticket closed → notify ticket creator
- Channels: in-app notification (DB-driven) + optional email

---

## 6. Database Schema Changes Required

### New Tables

**`ticket_status_histories`**
```sql
id, ticket_id, from_status, to_status, changed_by (FK users), note, created_at
```

**`ticket_sla_snapshots`** (or add columns directly to `tickets`)
```sql
ticket_id, sla_policy_id, resolution_minutes, deadline, breached_at
```

**`notifications`**
```sql
id, user_id, type, data (json), read_at, created_at
```

**`performance_cache`** (optional — for query performance)
```sql
user_id, period (monthly), total, on_time, avg_minutes, updated_at
```

### Changes to Existing Tables

**`tasks` → `tickets`** (rename + additions):
- `ticket_no` VARCHAR UNIQUE (e.g. TKT-2024-00001)
- `sla_deadline` DATETIME
- `sla_breached` BOOLEAN DEFAULT false
- `assigned_at` DATETIME
- `resolved_at` DATETIME
- `closed_at` DATETIME
- `closed_by` FK users

---

## 7. UI Reform Notes

### Ticket List Page
- Color-coded SLA indicator (green / yellow / red)
- Countdown timer to SLA deadline
- Quick filters: region, status, priority, assigned user
- Optional Kanban view

### Ticket Detail Page
- Status timeline (visual status_histories)
- SLA progress bar
- Comment / activity feed
- File attachments (images)

### Dashboard (Admin / Supervisor)
- Summary cards
- Per-person performance table
- SLA compliance chart
- Open / overdue ticket counts

---

## 8. Reform Priority Order

```
1. [DB]     tickets table + ticket_status_histories
2. [LOGIC]  SLA snapshot on create + breach detection scheduled job
3. [UI]     Ticket list with countdown
4. [UI]     Ticket detail with status timeline
5. [PERM]   Shield permission matrix revision
6. [PERF]   Performance dashboard
7. [NOTIF]  Notification system
8. [REPORT] Export (Excel / PDF)
```

---

## 9. Coding Conventions (Project-wide)

- Write a Policy for every model (integrated with Shield)
- Use Repository pattern (service layer)
- Keep Livewire components small (single responsibility)
- Dispatch Jobs to queue (SLA breach check, notifications)
- Tests: Feature test for every critical business rule

---

## 10. Instructions for Claude Code

After reading this file:

1. If `CLAUDE.md` does not exist, read `php artisan route:list` output + all migrations and create it
2. Follow the reform priority order step by step
3. For each step, write migration + model + policy + Livewire component together
4. Do not delete existing code — refactor it
5. Do the `task` → `ticket` rename gradually (start with a model alias)
