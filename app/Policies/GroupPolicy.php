<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('group.manage') || $user->can('view_any_group');
    }

    public function view(User $user, Group $group): bool
    {
        return $user->can('group.manage') || $user->can('view_group');
    }

    public function create(User $user): bool
    {
        return $user->can('group.manage');
    }

    public function update(User $user, Group $group): bool
    {
        return $user->can('group.manage');
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->can('group.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('group.manage');
    }

    public function forceDelete(User $user, Group $group): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function restore(User $user, Group $group): bool
    {
        return $user->can('group.manage');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('group.manage');
    }

    public function replicate(User $user, Group $group): bool
    {
        return $user->can('group.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('group.manage');
    }
}
