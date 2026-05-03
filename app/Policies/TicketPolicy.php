<?php

// WARNING: Do NOT run `php artisan shield:generate --all` without
// restoring this file from git afterwards.
// shield:generate overwrites custom policy logic with stubs.
// Run: git checkout HEAD -- app/Policies/TicketPolicy.php
//      git checkout HEAD -- app/Policies/GroupPolicy.php
//      git checkout HEAD -- app/Policies/SlaPolicyPolicy.php

namespace App\Policies;

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

    public function delete(User $user, Ticket $ticket): bool
    {
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
