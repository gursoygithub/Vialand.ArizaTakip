<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Agreed grounds for an SLA exemption (agreement §6).
 */
enum ExemptionReasonEnum: string implements HasLabel
{
    case ExternalFactor = 'external_factor';
    case PowerOutage = 'power_outage';
    case Supplier = 'supplier';
    case WrongPriority = 'wrong_priority';

    public function getLabel(): string
    {
        return __('ui.exemption_reason_'.$this->value);
    }
}
