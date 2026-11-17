<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Shield's super_admin has no gate bypass here (define_via_gate = false) and
 * PermissionSeeder does not create resource permissions, so tests create the
 * ones shield:generate would create and grant them explicitly.
 */
trait GrantsShieldPermissions
{
    /**
     * @param  array<int, string>  $resources  Shield identifiers, e.g. "team", "ticket::type"
     */
    protected function userWithResourcePermissions(array $resources): User
    {
        $names = collect($resources)
            ->flatMap(fn (string $resource): array => collect(['view_any', 'view', 'create', 'update', 'delete', 'delete_any'])
                ->map(fn (string $prefix): string => "{$prefix}_{$resource}")
                ->all())
            ->each(fn (string $name) => Permission::findOrCreate($name, 'web'))
            ->all();

        $user = User::factory()->create();
        $user->givePermissionTo($names);

        return $user;
    }
}
