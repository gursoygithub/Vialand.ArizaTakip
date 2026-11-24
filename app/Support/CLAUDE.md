# Support Helpers Guide

## CompanyEmail (`App\Support\CompanyEmail`)
Classifies an address against the company domains on Sistem Ayarları
(`GeneralSettings::$mail_allowed_domains`): `corporate`, `personal`,
`invalid`, `missing`.

- `App\Listeners\BlockNonCorporateMail` cancels the mail channel of every
  notification whose address is not corporate (logged as "Mail skipped:
  address is not a company address."); the bell of the same notification
  still goes.
- Team people and CC people must have a corporate address
  (`TeamResource::requireEmail`, `TicketWorkflowService::watchersFor`); the CC
  picker only offers them.
- `Employee::scopeEmailStatus()` filters by the same classes plus
  `duplicate`; used by the Personeller filter and
  `App\Exports\EmployeeEmailIssuesExport` (HR list).
