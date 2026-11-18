<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The only reasons that may pause the SLA clock (agreement §4). Waiting
 * for an approval needs a note saying whose approval it is.
 */
enum HoldReasonEnum: string implements HasLabel
{
    case AwaitingRequester = 'awaiting_requester';
    case AwaitingParts = 'awaiting_parts';
    case AwaitingVendor = 'awaiting_vendor';
    case AwaitingApproval = 'awaiting_approval';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingRequester => __('ui.hold_awaiting_requester'),
            self::AwaitingParts => __('ui.hold_awaiting_parts'),
            self::AwaitingVendor => __('ui.hold_awaiting_vendor'),
            self::AwaitingApproval => __('ui.hold_awaiting_approval'),
        };
    }
}
