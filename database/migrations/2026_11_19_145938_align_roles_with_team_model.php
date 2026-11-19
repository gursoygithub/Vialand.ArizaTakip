<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * "Talepler" now checks its own Shield permissions (team::ticket) instead
     * of the legacy Ticket ones, so every role keeps exactly the access it
     * had. Roles left over from the old project with nobody in them are
     * removed, as are the team::ticket permissions no screen uses.
     */
    private const MOVED = [
        'view_any_ticket' => 'view_any_team::ticket',
        'view_ticket' => 'view_team::ticket',
        'create_ticket' => 'create_team::ticket',
    ];

    private const UNUSED_ROLES = ['admin', 'supervisor', 'technician', 'viewer', 'Yetkili Yönetici'];

    private const UNUSED_PERMISSIONS = ['update_team::ticket', 'delete_team::ticket', 'delete_any_team::ticket'];

    public function up(): void
    {
        foreach (self::MOVED as $new) {
            Permission::findOrCreate($new, 'web');
        }

        foreach (Role::query()->with('permissions')->get() as $role) {
            $names = $role->permissions->pluck('name');

            foreach (self::MOVED as $old => $new) {
                if ($role->name === 'super_admin' || $names->contains($old)) {
                    $role->givePermissionTo($new);
                }
            }
        }

        Role::query()
            ->whereIn('name', self::UNUSED_ROLES)
            ->whereDoesntHave('users')
            ->get()
            ->each(fn (Role $role) => $role->delete());

        Permission::query()->whereIn('name', self::UNUSED_PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deleted roles held no users and are not restored.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', array_values(self::MOVED))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
