<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The dashboard widgets are shown only to roles that have their
     * permission (Roller → Widget'lar). No role gets them here: the admin
     * ticks them per role.
     */
    private const WIDGETS = [
        'widget_RequestOverviewStats',
        'widget_MyRequestQueue',
        'widget_RequestsByStatusChart',
        'widget_OpenRequestsByTypeChart',
    ];

    public function up(): void
    {
        foreach (self::WIDGETS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_diff(self::WIDGETS, ['widget_RequestOverviewStats']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
