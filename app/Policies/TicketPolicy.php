<?php

// WARNING: Do NOT run `php artisan shield:generate --all` without
// restoring this file from git afterwards.
// shield:generate overwrites custom policy logic with stubs.
// Run: git checkout HEAD -- app/Policies/TicketPolicy.php
//      git checkout HEAD -- app/Policies/GroupPolicy.php
//      git checkout HEAD -- app/Policies/SlaPolicyPolicy.php

namespace App\Policies;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_ticket');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->can('view_ticket');
    }

    public function create(User $user): bool
    {
        return $user->can('create_ticket');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        // Terminal-state lock: tickets in a final lifecycle state are
        // immutable for everyone — including super_admin. Reopening goes
        // through TicketService::transition (CLOSED/RESOLVED → ASSIGNED),
        // not the Edit page.
        if (in_array($ticket->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::CANCELLED,
        ], true)) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.assign');
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.close');
    }

    /**
     * Reopen a terminal ticket back to ASSIGNED. The matrix only allows
     * RESOLVED/CLOSED → ASSIGNED, so this policy refuses anything else
     * up front. Restricted to the original creator OR super_admin —
     * the legacy `ticket.reopen` permission is no longer consulted.
     */
    public function reopen(User $user, Ticket $ticket): bool
    {
        if (in_array($ticket->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
        ], true) === false) {
            return false;
        }

        return $ticket->created_by === $user->id
            || $user->hasRole('super_admin');
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        // Terminal-state lock: mirrors update(). A finalised ticket is
        // immutable for everyone — including super_admin — so it cannot
        // be soft-deleted either. To delete a closed/resolved/cancelled
        // ticket, reopen it first via TicketService::transition
        // (CLOSED/RESOLVED → ASSIGNED).
        if (in_array($ticket->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::CANCELLED,
        ], true)) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function restore(User $user, Ticket $ticket): bool
    {
        return $user->can('delete_ticket');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('delete_ticket');
    }

    public function replicate(User $user, Ticket $ticket): bool
    {
        return $user->can('create_ticket');
    }

    public function reorder(User $user): bool
    {
        return $user->can('ticket.view.all');
    }
}
