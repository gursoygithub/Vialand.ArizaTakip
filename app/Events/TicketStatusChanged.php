<?php

namespace App\Events;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?TaskStatusEnum $from,
        public readonly TaskStatusEnum $to,
        public readonly User $by,
        public readonly ?string $note = null,
    ) {}
}
