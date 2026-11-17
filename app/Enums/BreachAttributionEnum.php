<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who carries a resolution SLA breach (agreement §5, decision 14).
 *
 * Person   — the employee who held the ticket when the deadline passed.
 * Handover — that employee took the ticket over with less than 25% of the
 *            SLA left; the breach stays with the team, not the person.
 * Dispatch — nobody was assigned when the deadline passed; shown on the
 *            team / supervisor board, never on a personal score.
 */
enum BreachAttributionEnum: string implements HasLabel
{
    case Person = 'person';
    case Handover = 'handover';
    case Dispatch = 'dispatch';

    public function getLabel(): string
    {
        return match ($this) {
            self::Person => __('ui.breach_person'),
            self::Handover => __('ui.breach_handover'),
            self::Dispatch => __('ui.breach_dispatch'),
        };
    }
}
