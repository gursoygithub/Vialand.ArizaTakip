# CLAUDE.md — Arıza Takip Project Guide

## Tech Stack
- **Laravel** 12.x | **Filament** 3.x (admin panel, Livewire-powered)
- **Shield**: bezhansalleh/filament-shield — Spatie Permission RBAC
- **LDAP**: directorytree/ldaprecord-laravel (Active Directory)
- **Media**: spatie/laravel-medialibrary (S3 disk, collection: `task_attachments`)
- **Activity**: spatie/laravel-activitylog
- **Queue**: `database` driver (`QUEUE_CONNECTION=database` in .env)
- **Schedule**: `bootstrap/app.php` → `withSchedule()` (no Kernel.php — Laravel 12 style)

## LDAP Authentication
- Config: `config/ldap.php` + `.env` (`LDAP_DEFAULT_HOSTS`, `LDAP_DEFAULT_BASE_DN`, etc.)
- Attributes synced via `App\Ldap\AttributeHandler`, called in `AppServiceProvider` on `Synchronized` event
- **First login**: user gets `default` role → zero permissions → admin must assign a real role

## Shield (Permissions)
- `default` role: zero permissions, always
- All authorization via `$this->authorize()`, `@can()`, or Filament policies — never hardcode role names
- Policies live in `app/Policies/`, bound in `AppServiceProvider`
- Permissions defined in `database/seeders/PermissionSeeder.php`

## Commands — always use `sail` prefix (Docker environment)
The `sail` binary lives at `./vendor/bin/sail` (NOT in PATH). Use it directly:
```bash
./vendor/bin/sail artisan migrate     # run migrations
./vendor/bin/sail artisan route:list  # list routes
./vendor/bin/sail artisan test        # run tests
./vendor/bin/sail composer require .. # install packages
./vendor/bin/sail artisan queue:work  # start queue worker
```
Container: `ariza-takip-paneli-laravel.test-1` (use `docker ps` to confirm).
Tests use SQLite in-memory (configured in `phpunit.xml`).

## Job Dispatching
- All async work via Jobs: `MyJob::dispatch()` — never `sync` driver for production
- Schedule entries: add in `bootstrap/app.php` → `withSchedule()` callback

## Files Never to Modify
- `vendor/` — use `sail composer`
- `.env` — update `.env.example` and document changes in CLAUDE.md
- Existing migrations — always create new migrations, never edit old ones

## Conventions
- All new code in **English** (class names, column names, variables, comments)
- Turkish → English column map (REFORM.md §3):
  - `bolge_id` → `area_id` | `lokasyon_id` → `sub_area_id` | `birim_id` → `unit_id`
  - `oncelik` → `priority` | `durum` → `status` | `ariza_tarihi` → `task_date`
  - `isim` → `name` | `amir_id` → `employee_id` (supervisor FK)
- UI: Filament 3.x only — code in `app/Filament/` (Resources, Pages, Widgets)
- Business logic: `app/Services/` only — never in Filament Resources

## Company Access

Users are scoped to one or more companies. The canonical helper is on the User model:

```php
$user->scopedCompanyIds(): array
```

- Returns `[]` for admins (`ticket.view.all`) — caller treats as "no filter".
- Returns `[own_company_id]` for normal users (derived from `employee.company_id`).
- Returns `[id1, id2, ...]` for users granted extra access via `user_company_access`.

Pivot table: **`user_company_access`** (`user_id`, `company_id`, `granted_by`,
timestamps; UNIQUE on `(user_id, company_id)`). Eloquent relation:
`$user->extraCompanies()` (BelongsToMany with `granted_by` pivot).

Managed in the panel via the `CompanyAccessRelationManager` on `UserResource`,
gated behind `user.role.assign`.

Usage rules:

- **In new code, always use `scopedCompanyIds()` with `->whereIn('company_id', $ids)`**.
- The legacy `scopedCompanyId()` (singular) is `@deprecated` — it returns `null` for
  admins AND for multi-company users (single id is ambiguous), so callers built
  on it silently disable scoping for multi-company users. Do not extend its use.
- `Ticket::scopeVisibleBy` already applies the company filter to the
  `ticket.view.group` branch. `TicketResource::getEloquentQuery` adds it on
  top for the panel list — both are correct (idempotent for admins).

## Gotchas

### shield:generate overwrites custom policies
`php artisan shield:generate --all` rewrites these files with auto-generated
stubs that use the wrong permission names (`view_ticket`, `create_ticket`)
instead of our namespaced ones (`ticket.view.all`, `ticket.create`):
- `app/Policies/TicketPolicy.php`
- `app/Policies/GroupPolicy.php`
- `app/Policies/SlaPolicyPolicy.php`

If you must run `shield:generate --all` (e.g. after adding a new resource),
restore these three policies from git immediately afterwards:
```bash
git checkout HEAD -- app/Policies/TicketPolicy.php
git checkout HEAD -- app/Policies/GroupPolicy.php
git checkout HEAD -- app/Policies/SlaPolicyPolicy.php
```
Each file has a header comment repeating this warning.

### shield:generate inside InitSeeder prompts for panel
`InitSeeder::run()` calls `Artisan::call('shield:generate', ['--all' => true])`
without `--panel`, which throws `NonInteractiveValidationException` under
`db:seed --no-interaction`. Workaround: run `shield:generate --all
--panel=dashboard --no-interaction` manually before seeding, then run the
remaining seeder steps.

### UnitSeeder needs an authenticated user
`Unit::booted()` sets `created_by = auth()->id()` in the `creating` hook.
`UnitSeeder` has no auth context, so `created_by` becomes `NULL` and the
NOT NULL constraint fails. Run it under an authenticated user:
```php
auth()->login(User::where('username', 'sa')->first());
(new UnitSeeder)->run();
```
