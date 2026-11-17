<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Final result of one SLA cycle. Only OnTime and Breached enter
 * performance figures; the others are kept for audit only.
 */
enum SlaCycleOutcomeEnum: string implements HasLabel
{
    case OnTime = 'on_time';
    case Breached = 'breached';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Transferred = 'transferred';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnTime => __('ui.outcome_on_time'),
            self::Breached => __('ui.outcome_breached'),
            self::Cancelled => __('ui.outcome_cancelled'),
            self::Rejected => __('ui.outcome_rejected'),
            self::Transferred => __('ui.outcome_transferred'),
        };
    }

    public function countsForPerformance(): bool
    {
        return in_array($this, [self::OnTime, self::Breached], true);
    }
}
