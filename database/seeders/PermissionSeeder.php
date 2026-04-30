<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Single source of truth for:
 *  - Defining every custom permission (firstOrCreate-style; idempotent)
 *  - Wiring each permission to the correct role
 *
 * Auto-generated Shield resource/widget/page permissions are produced by
 * `php artisan shield:generate --all` and live in the `permissions` table
 * alongside the custom ones — they are NOT redefined here. However, we DO
 * sync `super_admin` to Permission::all() at the end of run() so it always
 * has every permission in the table (Shield's `define_via_gate` is false).
 *
 * Run order matters when introducing a new resource:
 *   1) `shield:generate --all`     → adds resource permissions
 *   2) `db:seed --class=PermissionSeeder` → grants them to super_admin
 */
class PermissionSeeder extends Seeder
{
    /**
     * All custom permissions — namespaced (dot.notation) and legacy (snake_case).
     * Anything Shield auto-generates is intentionally NOT listed here.
     */
    private const CUSTOM_PERMISSIONS = [
        // Reform §5.4 — namespaced
        'ticket.create',
        'ticket.view.own',
        'ticket.view.group',
        'ticket.view.all',
        'ticket.assign',
        'ticket.close',
        'ticket.delete',
        'sla.manage',
        'group.manage',
        'user.role.assign',
        'report.view',

        // Legacy (kept for backward compat with TaskResource and other upstream code)
        'view_all_users',
        'view_tc_no',
        'view_all_areas',
        'view_all_sub_areas',
        'view_all_tasks',
        'create_custom_area',
        'create_custom_sub_area',
        'export_tasks',
        'can_close_task',
        'view_all_units',
        'view_all_subcontractors',
        'view_all_subcontractor_employees',
        'can_assign_task',
        'can_reopen_task',
        'view_all_companies',
        'view_all_sla_policies',
        'view_kpi_dashboard',
        'manage_settings',
        'create_custom_group',
        'create_custom_group_member',
        'view_all_groups',
        'view_all_group_members',

        // Widget permissions Shield missed during discovery
        'widget_DailyTaskPerformance',
    ];

    private const ROLES = [
        'super_admin', 'admin', 'supervisor', 'technician', 'viewer', 'default',
    ];

    /**
     * Per-role permission grants. super_admin is handled separately
     * (gets ALL permissions in the table — see run()).
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'ticket.create',
            'ticket.view.all',
            'ticket.assign',
            'ticket.close',
            'ticket.delete',
            'sla.manage',
            'group.manage',
            'user.role.assign',
            'report.view',
            'view_all_users',
            'view_tc_no',
            'view_all_areas',
            'view_all_sub_areas',
            'view_all_tasks',
            'create_custom_area',
            'create_custom_sub_area',
            'export_tasks',
            'can_close_task',
            'view_all_units',
            'view_all_subcontractors',
            'view_all_subcontractor_employees',
            'can_assign_task',
            'can_reopen_task',
            'view_all_companies',
            'view_all_sla_policies',
            'view_kpi_dashboard',
            'manage_settings',
            'create_custom_group',
            'create_custom_group_member',
            'view_all_groups',
            'view_all_group_members',
            'widget_DailyTaskPerformance',
        ],
        'supervisor' => [
            'ticket.create',
            'ticket.view.group',
            'ticket.assign',
            'ticket.close',
            'report.view',
            'can_close_task',
            'can_assign_task',
            'can_reopen_task',
            'view_all_areas',
            'view_all_sub_areas',
            'view_all_units',
            'view_all_sla_policies',
            'view_all_groups',
            'view_all_group_members',
            'view_kpi_dashboard',
            'widget_DailyTaskPerformance',
        ],
        'technician' => [
            'ticket.create',
            'ticket.view.own',
            'can_reopen_task',
        ],
        'viewer' => [
            'ticket.view.own',
        ],
        'default' => [
            // Zero permissions — awaits role assignment from admin
        ],
    ];

    public function run(): void
    {
        // 1. Custom permissions (idempotent)
        foreach (self::CUSTOM_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Roles (idempotent)
        foreach (self::ROLES as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 3. super_admin gets EVERY permission in the table (custom + Shield-generated).
        //    Required because Shield's `define_via_gate` is false — there is no
        //    Gate::before bypass for super_admin, so $user->can() needs the permission
        //    to be directly assigned via role.
        Role::where('name', 'super_admin')->first()
            ->syncPermissions(Permission::pluck('name')->all());

        // 4. Other roles → scoped permissions
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::where('name', $roleName)->first()->syncPermissions($permissions);
        }
    }
}
