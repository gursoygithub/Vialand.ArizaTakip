<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ticket.view.own')
            || $user->can('ticket.view.group')
            || $user->can('ticket.view.all');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->can('ticket.view.all')) {
            return true;
        }

        if ($user->can('ticket.view.group')) {
            return $this->isInSameArea($user, $ticket);
        }

        // ticket.view.own: only own tickets
        return $ticket->created_by === $user->id
            || $this->isAssignedEmployee($user, $ticket);
    }

    public function create(User $user): bool
    {
        return $user->can('ticket.create');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if ($user->can('ticket.view.all')) {
            return true;
        }

        if ($user->can('ticket.view.group')) {
            return $this->isInSameArea($user, $ticket);
        }

        return $this->isAssignedEmployee($user, $ticket)
            || $ticket->created_by === $user->id;
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
        return $user->can('ticket.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('ticket.delete');
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
        return $user->can('ticket.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('ticket.delete');
    }

    public function replicate(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.create');
    }

    public function reorder(User $user): bool
    {
        return $user->can('ticket.view.all');
    }

    private function isInSameArea(User $user, Ticket $ticket): bool
    {
        return \App\Models\Group::where('area_id', $ticket->area_id)
            ->whereHas('members', fn ($q) =>
                $q->whereHas('employee', fn ($eq) =>
                    $eq->where('email', $user->email)
                )
            )
            ->exists();
    }

    private function isAssignedEmployee(User $user, Ticket $ticket): bool
    {
        if (!$ticket->employee_id) {
            return false;
        }

        return \App\Models\Employee::where('id', $ticket->employee_id)
            ->where('email', $user->email)
            ->exists();
    }
}
