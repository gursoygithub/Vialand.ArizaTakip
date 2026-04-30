<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CustomPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissionName = [
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
        ];

        foreach ($permissionName as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name]);
        }
    }
}
