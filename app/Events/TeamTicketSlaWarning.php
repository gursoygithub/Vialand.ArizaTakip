<?php

namespace App\Events;

use App\Models\TicketSlaCycle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 80% of a team ticket's resolution time is used. Fired once per cycle.
 */
class TeamTicketSlaWarning
{
    use Dispatchable, SerializesModels;

    public function __construct(public TicketSlaCycle $cycle) {}
}
