<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Backward-compatibility shim. The canonical seeder is now PermissionSeeder.
 * This class is kept so old commands like
 *   `php artisan db:seed --class=CustomPermissionSeeder`
 * continue to work.
 */
class CustomPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
    }
}
