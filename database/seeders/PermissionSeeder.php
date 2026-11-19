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
 * production — this seeder grants nothing except to super_admin. It only
 * guarantees:
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
     * Custom permissions plus the Shield page/widget permissions, so test
     * DBs (which only run this seeder, not shield:generate) have them.
     * firstOrCreate is idempotent in production.
     */
    private const CUSTOM_PERMISSIONS = [
        // "Talepler" (TeamTicketResource): its own Shield permissions.
        'view_any_team::ticket',
        'view_team::ticket',
        'create_team::ticket',

        // Request visibility beyond one's own involvement.
        'ticket.view.all',
        // See requests raised or handled by people of the viewer's companies.
        'ticket.view.company',
        // Receives escalations of requests still open X hours after an SLA breach.
        'ticket.sla.escalation',
        // Decides SLA exemption requests (never for the approver's own team).
        'sla.exemption.approve',
        // Excel / PDF export of the team performance report.
        'ticket.export',

        // User management
        'user.role.assign',

        // Resource scope-bypass (view all records, irrespective of created_by)
        'view_all_companies',
        'view_all_areas',
        'view_all_sub_areas',
        'view_all_employees',
        'view_all_users',

        // Misc
        'view_tc_no',

        // Dashboard widgets (Shield layer 3; listed for test DB compatibility)
        'widget_RequestOverviewStats',
        'widget_MyRequestQueue',
        'widget_RequestsByStatusChart',
        'widget_OpenRequestsByTypeChart',

        // Page access (Shield layer 2; listed for test DB compatibility)
        'page_ManageGeneralSettings',
        'page_NotificationPreferences',
        'page_TeamPerformanceReport',
    ];

    private const ROLES = [
        'super_admin', 'manager', 'default',
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

        // 3. super_admin also holds every permission row, so the role screen
        //    shows it fully ticked (access itself comes from Shield's
        //    Gate::before). This is the ONLY syncPermissions call.
        Role::where('name', 'super_admin')->first()
            ->syncPermissions(Permission::pluck('name')->all());
    }
}
