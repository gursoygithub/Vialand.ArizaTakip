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
        ];

        foreach ($permissionName as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name]);
        }
    }


}
