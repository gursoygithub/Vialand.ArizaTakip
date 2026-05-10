<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename report.view → ticket.export (preserves all assignments)
        DB::table('permissions')
            ->where('name', 'report.view')
            ->update(['name' => 'ticket.export']);

        // 2. Migrate view_all_tasks → ticket.view.all
        $oldId = DB::table('permissions')->where('name', 'view_all_tasks')->value('id');
        $newId = DB::table('permissions')->where('name', 'ticket.view.all')->value('id');

        if ($oldId && $newId) {
            // Copy role assignments (skip duplicates)
            DB::statement("
                INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
                SELECT ?, role_id
                FROM role_has_permissions
                WHERE permission_id = ?
            ", [$newId, $oldId]);

            // Copy direct user assignments
            DB::statement("
                INSERT IGNORE INTO model_has_permissions (permission_id, model_type, model_id)
                SELECT ?, model_type, model_id
                FROM model_has_permissions
                WHERE permission_id = ?
            ", [$newId, $oldId]);

            // Delete old permission and its assignments
            DB::table('role_has_permissions')->where('permission_id', $oldId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $oldId)->delete();
            DB::table('permissions')->where('id', $oldId)->delete();
        }

        // 3. Delete orphan permissions
        $orphans = [
            'manage_settings',
            'group.manage',
            'view_kpi_dashboard',
            'ticket.reopen',
            'can_close_task',
            'can_assign_task',
            'can_reopen_task',
            'widget_DailyTaskPerformance',
        ];

        $orphanIds = DB::table('permissions')
            ->whereIn('name', $orphans)
            ->pluck('id')
            ->toArray();

        if (!empty($orphanIds)) {
            DB::table('role_has_permissions')->whereIn('permission_id', $orphanIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $orphanIds)->delete();
            DB::table('permissions')->whereIn('id', $orphanIds)->delete();
        }

        // 4. Reset Spatie permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No safe rollback — orphan permissions had no purpose; renames
        // are canonical. Restore from DB backup if rollback needed.
    }
};
