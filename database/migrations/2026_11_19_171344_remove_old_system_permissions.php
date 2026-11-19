<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The old ticket flow (areas → units → groups → SLA policies), its
     * dashboards and the screens taken over from other projects are gone,
     * so their permissions are removed from the role screen too. Runs after
     * align_roles_with_team_model, which already moved the ticket grants to
     * "Talepler". The old tables and rows stay untouched as an archive.
     */
    private const EXACT = [
        'view_any_ticket', 'view_ticket', 'create_ticket', 'update_ticket', 'delete_ticket', 'delete_any_ticket',
        'ticket.view.own', 'ticket.view.group', 'ticket.assign', 'ticket.close',
        'export_tasks', 'sla.manage',
        'view_all_units', 'view_all_groups', 'view_all_group_members', 'view_all_sla_policies',
        'view_all_subcontractors', 'view_all_subcontractor_employees',
        'create_custom_area', 'create_custom_sub_area', 'create_custom_group', 'create_custom_group_member',
        'widget_TicketStatsOverview', 'widget_TicketsByStatusChart', 'widget_TicketsByPriorityChart',
        'widget_SlaComplianceTrendChart', 'widget_RecentTicketsTable',
        'page_PerformanceDashboard', 'page_CompanySetupWizard',
    ];

    /**
     * Shield CRUD permissions of the removed resources (view_unit, delete_any_group …).
     */
    private const RESOURCE_SUFFIXES = ['unit', 'group', 'sla::policy', 'subcontractor', 'subcontractor::employee'];

    private const PREFIXES = [
        'view', 'view_any', 'create', 'update', 'restore', 'restore_any', 'replicate', 'reorder',
        'delete', 'delete_any', 'force_delete', 'force_delete_any',
    ];

    public function up(): void
    {
        $names = self::EXACT;

        foreach (self::RESOURCE_SUFFIXES as $suffix) {
            foreach (self::PREFIXES as $prefix) {
                $names[] = "{$prefix}_{$suffix}";
            }
        }

        Permission::query()->whereIn('name', $names)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The removed screens do not come back, so neither do their permissions.
     */
    public function down(): void {}
};
