<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Single source of truth for:
 *  - Defining every custom permission (firstOrCreate-style; idempotent)
 *  - Granting each role its DEFAULT permissions on first run
 *
 * IMPORTANT: Only super_admin uses syncPermissions (replace).
 * All other roles use additive givePermissionTo so that
 * permissions assigned via the Shield UI are never overwritten.
 * To reset a role's permissions, do it manually via the UI
 * or write a dedicated one-time migration.
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
        // Shield-standard CRUD permissions for the Ticket resource. These are
        // also produced by `shield:generate --all` in prod, but we list them
        // here so test DBs (which only run PermissionSeeder, not shield:generate)
        // get them too. firstOrCreate is idempotent in prod.
        'view_any_ticket',
        'view_ticket',
        'create_ticket',
        'update_ticket',
        'delete_ticket',
        'delete_any_ticket',

        // Reform §5.4 — truly custom (scope + transition) permissions.
        // ticket.create / ticket.delete intentionally removed: replaced by
        // Shield-standard create_ticket / delete_ticket above.
        'ticket.view.own',
        'ticket.view.group',
        'ticket.view.all',
        'ticket.assign',
        'ticket.close',
        'ticket.reopen',
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

        // New ticket dashboard widgets (manual — shield:generate has been
        // unreliable for widget discovery in this codebase)
        'widget_TicketStatsOverview',
        'widget_TicketsByStatusChart',
        'widget_TicketsByPriorityChart',
        'widget_SlaComplianceTrendChart',
        'widget_RecentTicketsTable',

        // Page permissions (Shield layer 2). shield:generate creates these in prod;
        // we list them here so test DBs (which only run PermissionSeeder) get them.
        'page_PerformanceDashboard',
        'page_ManageGeneralSettings',
        'page_CompanySetupWizard',
    ];

    private const ROLES = [
        'super_admin', 'admin', 'supervisor', 'manager', 'technician', 'viewer', 'default',
    ];

    /**
     * Per-role DEFAULT permission grants applied additively.
     *
     * super_admin is handled separately (gets ALL permissions in the table —
     * see run()). Every role listed here is grown via givePermissionTo
     * filtered to perms it doesn't already have, so seeder reruns never
     * remove what was added in the Shield UI.
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'view_any_ticket',
            'view_ticket',
            'create_ticket',
            'update_ticket',
            'delete_ticket',
            'delete_any_ticket',
            'ticket.view.all',
            'ticket.assign',
            'ticket.close',
            'ticket.reopen',
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
            'widget_TicketStatsOverview',
            'widget_TicketsByStatusChart',
            'widget_TicketsByPriorityChart',
            'widget_SlaComplianceTrendChart',
            'widget_RecentTicketsTable',
            'page_PerformanceDashboard',
            'page_ManageGeneralSettings',
            'page_CompanySetupWizard',
        ],
        'supervisor' => [
            'view_any_ticket',
            'view_ticket',
            'create_ticket',
            'update_ticket',
            'ticket.view.group',
            'ticket.assign',
            'ticket.close',
            'ticket.reopen',
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
            'widget_TicketStatsOverview',
            'widget_TicketsByStatusChart',
            'widget_TicketsByPriorityChart',
            'widget_SlaComplianceTrendChart',
            'widget_RecentTicketsTable',
            'page_PerformanceDashboard',
        ],
        'manager' => [
            'view_any_ticket',
            'view_ticket',
            // Open new tickets — without this the "Arıza Talebi Oluştur" CTA
            // is hidden by TicketPolicy::create (gated on create_ticket).
            'create_ticket',
            // Row-level ticket visibility for BM and other mid-management roles.
            'ticket.view.own',
        ],
        'technician' => [
            'view_any_ticket',
            'view_ticket',
            'create_ticket',
            'update_ticket',
            'ticket.view.own',
            'can_reopen_task',
            'widget_TicketStatsOverview',
            'widget_TicketsByStatusChart',
            'widget_TicketsByPriorityChart',
        ],
        'viewer' => [
            'view_any_ticket',
            'view_ticket',
            'ticket.view.own',
        ],
        'default' => [
            // Zero permissions — awaits role assignment from admin.
        ],
    ];

    /**
     * Permissions removed from this seeder that should be purged from the DB.
     * Replaced by Shield-standard equivalents (create_ticket, delete_ticket,
     * delete_any_ticket) handled via shield:generate.
     */
    private const REMOVED_PERMISSIONS = [
        'ticket.create',
        'ticket.delete',
    ];

    public function run(): void
    {
        // 0. Drop legacy duplicates that have been migrated to Shield-standard
        //    permissions. Spatie's pivot rows clean up via FK cascade.
        DB::table('permissions')
            ->whereIn('name', self::REMOVED_PERMISSIONS)
            ->delete();

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
        //    to be directly assigned via role. This is the ONLY syncPermissions call.
        Role::where('name', 'super_admin')->first()
            ->syncPermissions(Permission::pluck('name')->all());

        // 4. Every other role grows additively. We only attach the perms it
        //    doesn't already hold, so anything an admin granted via the Shield
        //    UI (or removed there on purpose) is never wiped on reseed.
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if (!$role) {
                continue;
            }

            $missing = collect($permissions)
                ->filter(fn (string $perm) => !$role->hasPermissionTo($perm))
                ->values()
                ->all();

            if (!empty($missing)) {
                $role->givePermissionTo($missing);
            }
        }
    }
}
