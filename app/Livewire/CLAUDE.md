# Livewire / Filament UI Guide

> **This project uses Filament 3.x** (which runs on Livewire internally).
> All UI code lives in `app/Filament/`, not `app/Livewire/`.
> No standalone Livewire components are used.

## Filament Structure
- `app/Filament/Resources/` — CRUD Resources (one per model)
- `app/Filament/Pages/` — custom pages (dashboard, reports)
- `app/Filament/Widgets/` — dashboard charts and stat cards
- `app/Filament/Resources/{Name}Resource/Pages/` — List, Create, Edit, View pages

## Single Responsibility
- One Resource per model
- One Widget per chart/metric
- Business logic stays in `app/Services/` — never inline in Resources

## SLA Auto-Resolution
When `area_id` changes in ticket forms, use `->live()->afterStateUpdated()` on the Select
to call SlaService and show a preview of the resolved SLA deadline.

## Validation Patterns
```php
Forms\Components\Select::make('area_id')
    ->required()
    ->validationMessages(['required' => __('ui.required')])
```

## Event Naming
- Filament/Livewire events: kebab-case → `ticket-status-changed`, `sla-breached`
- Dispatch: `$this->dispatch('event-name', data: [...])`

## File Upload (ticket attachments)
- Component: `SpatieMediaLibraryFileUpload`
- Collection: `task_attachments`, disk: `s3`, **multi-file** (model has no `singleFile()`)
- Form caps via `->multiple()->maxFiles(5)->maxSize(10240)` and `->acceptedFileTypes([...image/jpeg, image/png, image/webp, application/pdf])`

## Live Polling (SLA countdown)
- Add `protected static ?string $pollingInterval = '60s';` to ListTickets page
- SLA color logic in `TextColumn::make('sla_deadline')->color(fn($record) => ...)`

## Notification Bell
- Panel has `->databaseNotifications()` enabled in `DashboardPanelProvider`
- Filament renders the bell automatically: unread badge, dropdown of last notifications, mark-as-read on click, mark-all-read button
- Our notification classes return `FilamentNotification::getDatabaseMessage()` from `toDatabase()` so the bell renders title/body/icon/action correctly

## Locale & Date Formatting
- `AppServiceProvider::boot` sets `Carbon::setLocale('tr')` + `setlocale(LC_TIME, 'tr_TR.UTF-8', 'tr_TR', 'tr')`
- Filament defaults: `Table::$defaultDateTimeDisplayFormat = 'd F Y - H:i'`, same for Infolist
- For Turkish month names in custom blade output, use `$carbon->translatedFormat('d F Y H:i')` — never `format()`
