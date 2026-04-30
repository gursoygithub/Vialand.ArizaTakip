<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Permissions from REFORM.md §5.4 plus existing legacy permissions.
     */
    private const PERMISSIONS = [
        // Ticket permissions (new)
        'ticket.create',
        'ticket.view.own',
        'ticket.view.group',
        'ticket.view.all',
        'ticket.assign',
        'ticket.close',
        'ticket.delete',

        // SLA & group management
        'sla.manage',
        'group.manage',

        // User management
        'user.role.assign',

        // Reporting
        'report.view',

        // Legacy permissions (keep for backward compat)
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
    ];

    /**
     * Role → permission assignments per REFORM.md §5.4.
     * super_admin is handled by Shield (has all permissions automatically).
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
            // Zero permissions — awaits role assignment
        ],
    ];

    public function run(): void
    {
        // Ensure all permissions exist
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Ensure all roles exist and assign permissions
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }
    }
}
