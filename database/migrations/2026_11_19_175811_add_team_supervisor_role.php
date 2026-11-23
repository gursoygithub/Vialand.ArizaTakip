<?php

use App\Services\SupervisorRoleService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * "Ekip Amiri": held automatically by everyone who supervises a team.
     * Starts with the request screens and the dashboard; the admin can
     * change its boxes on the Roller screen like any other role.
     */
    private const PERMISSIONS = [
        'view_any_team::ticket',
        'view_team::ticket',
        'create_team::ticket',
        'widget_RequestOverviewStats',
        'widget_MyRequestQueue',
        'widget_RequestsByStatusChart',
        'widget_OpenRequestsByTypeChart',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate(SupervisorRoleService::ROLE, 'web')->givePermissionTo(self::PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(SupervisorRoleService::class)->syncAll();
    }

    public function down(): void
    {
        Role::query()->where('name', SupervisorRoleService::ROLE)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
