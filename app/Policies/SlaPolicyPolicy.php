<?php

// WARNING: shield:generate overwrites this file with an auto-generated
// stub that uses the wrong permission names (view_sla_policy vs the
// project's namespaced sla.view). Always pass
// --ignore-existing-policies when invoking it manually:
//   php artisan shield:generate --all --panel=dashboard --ignore-existing-policies
// InitSeeder already does this, so migrate:fresh --seed is safe.
// If you forget the flag, restore from git:
//   git checkout HEAD -- app/Policies/TicketPolicy.php app/Policies/GroupPolicy.php app/Policies/SlaPolicyPolicy.php

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
