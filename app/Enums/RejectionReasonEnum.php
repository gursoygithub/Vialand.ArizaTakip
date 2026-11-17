<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a supervisor rejected a request. Rejected requests never count
 * towards anyone's performance.
 */
enum RejectionReasonEnum: string implements HasLabel
{
    case Duplicate = 'duplicate';
    case OutOfScope = 'out_of_scope';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Duplicate => __('ui.rejection_duplicate'),
            self::OutOfScope => __('ui.rejection_out_of_scope'),
            self::Invalid => __('ui.rejection_invalid'),
        };
    }
}
