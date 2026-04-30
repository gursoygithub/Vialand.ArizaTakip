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
