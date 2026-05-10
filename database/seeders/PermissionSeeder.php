<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission existence guarantor.
 *
 * Seeds Shield CRUD permissions (test DB only — production gets these
 * from shield:generate) and custom permissions (always seeded).
 *
 * Role assignments are managed exclusively via the Shield UI in
 * production — this seeder does NOT grant permissions to admin,
 * supervisor, manager, technician, or viewer. It only guarantees:
 *
 *   1. All permission rows exist in the DB
 *   2. All role rows exist in the DB
 *   3. super_admin syncs all permissions (system guarantee)
 *
 * For any other role/permission assignment, use the Shield UI.
 */
class PermissionSeeder extends Seeder
{
    /**
     * All custom permissions.
     *
     * Shield-standard CRUD and page/widget permissions are also listed here
     * so test DBs (which only run PermissionSeeder, not shield:generate) get
     * them. firstOrCreate is idempotent in production.
     *
     * NOTE: ticket.view.all is the canonical Ticket scope-bypass permission.
     * view_all_tickets is intentionally NOT added — Ticket has its own
     * 4-branch scopeVisibleBy() logic and ticket.view.* names document the
     * levels (own/group/all). Other resources use the view_all_X pattern.
     */
    private const CUSTOM_PERMISSIONS = [
        // Shield-standard CRUD permissions for the Ticket resource.
        // Listed here so test DBs (which only run PermissionSeeder, not
        // shield:generate) have them. firstOrCreate is idempotent in prod.
        'view_any_ticket',
        'view_ticket',
        'create_ticket',
        'update_ticket',
        'delete_ticket',
        'delete_any_ticket',

        // Ticket visibility scope (3 mutually exclusive levels)
        'ticket.view.own',
        'ticket.view.group',
        'ticket.view.all',

        // Ticket actions
        'ticket.assign',
        'ticket.close',
        'ticket.export',

        // SLA
        'sla.manage',

        // User management
        'user.role.assign',

        // Resource scope-bypass (view all records, irrespective of created_by)
        'view_all_companies',
        'view_all_areas',
        'view_all_sub_areas',
        'view_all_sla_policies',
        'view_all_groups',
        'view_all_group_members',
        'view_all_units',
        'view_all_employees',
        'view_all_users',
        'view_all_subcontractors',
        'view_all_subcontractor_employees',

        // Misc
        'view_tc_no',
        'create_custom_area',
        'create_custom_sub_area',
        'create_custom_group',
        'create_custom_group_member',
        'export_tasks',      // legacy — kept for now; see migration phase for cleanup

        // Widget permissions (listed for test DB compatibility; shield:generate owns these in prod)
        'widget_TicketStatsOverview',
        'widget_TicketsByStatusChart',
        'widget_TicketsByPriorityChart',
        'widget_SlaComplianceTrendChart',
        'widget_RecentTicketsTable',

        // Page access (Shield layer 2; listed for test DB compatibility)
        'page_PerformanceDashboard',
        'page_ManageGeneralSettings',
        'page_CompanySetupWizard',
        'page_NotificationPreferences',
    ];

    private const ROLES = [
        'super_admin', 'admin', 'supervisor', 'manager', 'technician', 'viewer', 'default',
    ];

    public function run(): void
    {
        // 1. Ensure all custom permissions exist (idempotent).
        foreach (self::CUSTOM_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Ensure all roles exist so Shield UI can display and assign them.
        foreach (self::ROLES as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 3. super_admin gets EVERY permission in the table (custom + Shield-generated).
        //    Required because Shield's `define_via_gate` is false — there is no
        //    Gate::before bypass for super_admin, so $user->can() needs the permission
        //    to be directly assigned via role. This is the ONLY syncPermissions call.
        Role::where('name', 'super_admin')->first()
            ->syncPermissions(Permission::pluck('name')->all());
    }
}
