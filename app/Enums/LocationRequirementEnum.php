<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Whether a ticket type asks for a location (area / sub-area) on its form.
 */
enum LocationRequirementEnum: string implements HasLabel
{
    case Hidden = 'hidden';
    case Optional = 'optional';
    case Required = 'required';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hidden => __('ui.location_hidden'),
            self::Optional => __('ui.location_optional'),
            self::Required => __('ui.location_required'),
        };
    }
}
