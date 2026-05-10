<?php

// WARNING: shield:generate overwrites this file with an auto-generated
// stub that uses the wrong permission names (view_group vs the
// project's namespaced group.view.all). Always pass
// --ignore-existing-policies when invoking it manually:
//   php artisan shield:generate --all --panel=dashboard --ignore-existing-policies
// InitSeeder already does this, so migrate:fresh --seed is safe.
// If you forget the flag, restore from git:
//   git checkout HEAD -- app/Policies/TicketPolicy.php app/Policies/GroupPolicy.php app/Policies/SlaPolicyPolicy.php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_group');
    }

    public function view(User $user, Group $group): bool
    {
        return $user->can('view_group');
    }

    public function create(User $user): bool
    {
        return $user->can('create_group');
    }

    public function update(User $user, Group $group): bool
    {
        return $user->can('update_group') && $group->created_by === $user->id;
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->can('delete_group') && $group->created_by === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_group');
    }

    public function forceDelete(User $user, Group $group): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Group $group): bool
    {
        return $user->can('delete_group');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('delete_any_group');
    }

    public function replicate(User $user, Group $group): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
