<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Keeps the "Ekip Amiri" role in step with the Ekipler screen: whoever
 * supervises at least one team holds it, nobody else does. The role only
 * decides what supervisors see on the panel (dashboard charts …); what
 * they may do on a request still comes from the team itself.
 *
 * People are matched to panel accounts by e-mail (like User::employee()),
 * so a person with two employee records is still treated as one.
 */
class SupervisorRoleService
{
    public const ROLE = 'Ekip Amiri';

    public function syncUser(User $user): void
    {
        if (blank($user->email)) {
            return;
        }

        $role = Role::findOrCreate(self::ROLE, 'web');

        $supervises = Team::query()
            ->whereIn('supervisor_employee_id', Employee::query()->where('email', $user->email)->select('id'))
            ->exists();

        if ($supervises && ! $user->hasRole($role)) {
            $user->assignRole($role);
        } elseif (! $supervises && $user->hasRole($role)) {
            $user->removeRole($role);
        }
    }

    public function syncEmployee(?int $employeeId): void
    {
        $email = $employeeId ? Employee::query()->whereKey($employeeId)->value('email') : null;

        if (blank($email)) {
            return;
        }

        User::query()->where('email', $email)->get()->each(fn (User $user) => $this->syncUser($user));
    }

    public function syncAll(): void
    {
        $emails = Employee::query()
            ->whereIn('id', Team::query()->whereNotNull('supervisor_employee_id')->select('supervisor_employee_id'))
            ->pluck('email');

        User::query()
            ->whereIn('email', $emails)
            ->orWhereHas('roles', fn ($query) => $query->where('name', self::ROLE))
            ->get()
            ->each(fn (User $user) => $this->syncUser($user));
    }
}
