<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Services\SlaService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Server-side SLA policy guard.
     *
     * Runs after Filament's own required-field validation but before the
     * Eloquent record is created. Any submission that reaches this hook is
     * guaranteed to have area_id, unit_id, and priority already validated as
     * present by the form's ->required() rules.
     *
     * FIX 2 (UI submit-disable) is intentionally skipped:
     *   - The unit_id dropdown only lists SLA-covered units for the selected
     *     area, so the "area has no SLA at all" case already produces an empty
     *     dropdown that prevents submission via the required() rule.
     *   - The sla_preview placeholder reactively shows a red
     *     "Bu kombinasyon için tanımlı SLA yok" warning for the remaining
     *     gap (area+unit pair has SLA for some priorities but not the one
     *     currently selected).
     *   - Disabling Filament's CreateRecord submit button reactively requires
     *     overriding getFormActions() and binding a Livewire reactive property
     *     to the SLA-preview result — significant custom code for a case that
     *     is already caught here with a clear field-level error on submit.
     */
    protected function beforeCreate(): void
    {
        $areaId    = ($this->data['area_id']     ?? null) ? (int) $this->data['area_id']     : null;
        $unitId    = ($this->data['unit_id']     ?? null) ? (int) $this->data['unit_id']     : null;
        $subAreaId = ($this->data['sub_area_id'] ?? null) ? (int) $this->data['sub_area_id'] : null;
        $priority  = $this->data['priority'] ?? null;

        if ($priority instanceof \BackedEnum) {
            $priority = $priority->value;
        }

        // Both area_id and priority are ->required() on the form. Filament's
        // built-in validation runs before beforeCreate() fires, so reaching
        // here without either value means a forged request — return silently
        // and let the required-field error surface instead.
        if (!$areaId || !$priority) {
            return;
        }

        $policy = app(SlaService::class)->resolvePolicy($areaId, $subAreaId, $unitId, $priority);

        if ($policy === null) {
            throw ValidationException::withMessages([
                'data.unit_id' => [
                    'Bu alan, birim ve öncelik kombinasyonu için tanımlı SLA politikası bulunamadı. Lütfen önce SLA tanımlayın.',
                ],
            ]);
        }
    }

    /**
     * Inject user_id before the record is created.
     *
     * user_id is the legacy reporter FK (tickets.user_id).  The MySQL migration
     * 2026_05_01_014538 made it nullable on production, but SQLite (used by the
     * test suite) still enforces NOT NULL because the migration is MySQL-only.
     * The Filament form has no user_id field, so without this hook the column
     * receives NULL and the insert fails on SQLite.  Injecting auth()->id() is
     * correct for both drivers and is a no-op for rows that already carry a
     * value.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = $data['user_id'] ?? auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
