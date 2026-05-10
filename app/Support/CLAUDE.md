# Support Helpers Guide

## DurationFormatter (`App\Support\DurationFormatter`)

Converts a raw integer minute count to a compact Turkish duration string.

```php
DurationFormatter::minutes(int $minutes): string
```

Output examples:
| Input | Output |
|---|---|
| 0 or negative | `'0dk'` |
| 45 | `'45dk'` |
| 90 | `'1sa 30dk'` |
| 120 | `'2sa'` |
| 1500 | `'1g'` |
| 1530 | `'1g 30dk'` |

**Use sites:**
- `ViewTicket` infolist — ON_HOLD banner duration (`on_hold_since->diffInMinutes(now())`)
- `exports/performance-report.blade.php` — "Ortalama Yanıt Süresi" and "Ortalama Çözüm Süresi" columns in the PDF
- `PerformanceDashboard::exportCsv()` — raw minute integers are written to CSV (not formatted); the formatter is used in the PDF path only

**Format rules:**
- `< 60 min` → `Xdk`
- `< 1440 min (1 day)` → `Xsa` or `Xsa Ydk`
- `≥ 1440 min` → `Xg` or `Xg Ysa` (minutes within the last hour are dropped at day scale)

## NotificationChannels (`App\Support\NotificationChannels`)

Constants file for channel name strings used by `UserNotificationPreference`.
Not a standalone utility — see `app/Notifications/CLAUDE.md` for channel rules.
