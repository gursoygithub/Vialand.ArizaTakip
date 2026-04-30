<?php

namespace App\Models;

/**
 * Backward-compatibility alias. All new code should use App\Models\Ticket.
 * This class exists so existing Filament Resources, seeders, and references
 * continue to work during the tasks → tickets migration.
 */
class Task extends Ticket
{
    // No additional logic — class alias only.
}
