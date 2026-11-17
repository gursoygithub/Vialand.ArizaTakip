<?php

namespace App\Events;

use App\Models\TicketSlaCycle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A team ticket's resolution deadline passed while it was still open.
 * Fired once per cycle, when the breach is first flagged.
 */
class TeamTicketSlaBreached
{
    use Dispatchable, SerializesModels;

    public function __construct(public TicketSlaCycle $cycle) {}
}
