<?php

// WARNING: Do NOT run `php artisan shield:generate --all` without
// restoring this file from git afterwards.
// shield:generate overwrites custom policy logic with stubs.
// Run: git checkout HEAD -- app/Policies/TicketPolicy.php
//      git checkout HEAD -- app/Policies/GroupPolicy.php
//      git checkout HEAD -- app/Policies/SlaPolicyPolicy.php

namespace App\Policies;

use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SlaPolicyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('sla.manage') || $user->can('view_all_sla_policies');
    }

    public function view(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->can('sla.manage') || $user->can('view_all_sla_policies');
    }

    public function create(User $user): bool
    {
        return $user->can('sla.manage');
    }

    public function update(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->can('sla.manage');
    }

    public function delete(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->can('sla.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('sla.manage');
    }

    public function forceDelete(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasRole(['super_admin']);
    }

    public function restore(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->can('sla.manage');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('sla.manage');
    }

    public function replicate(User $user, SlaPolicy $slaPolicy): bool
    {
        return $user->can('sla.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('sla.manage');
    }
}
