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
 * A person is all their HR records (same TC, see Employee::personIds()),
 * matched to panel accounts by e-mail like User::employee().
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

        $employeeIds = array_unique(array_merge(
            $user->employeeIds(),
            Employee::query()->where('email', $user->email)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ));

        $supervises = $employeeIds !== [] && Team::query()->whereIn('supervisor_employee_id', $employeeIds)->exists();

        if ($supervises && ! $user->hasRole($role)) {
            $user->assignRole($role);
        } elseif (! $supervises && $user->hasRole($role)) {
            $user->removeRole($role);
        }
    }

    public function syncEmployee(?int $employeeId): void
    {
        $emails = $employeeId ? Employee::query()->find($employeeId)?->personRecords()->pluck('email')->filter()->unique() : null;

        if (blank($emails) || $emails->isEmpty()) {
            return;
        }

        User::query()->whereIn('email', $emails)->get()->each(fn (User $user) => $this->syncUser($user));
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
