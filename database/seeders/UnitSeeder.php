<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // run truncate method to clear the units table before seeding
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Unit::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $units = [
            'İnşaat',
            'Elektrik',
            'Mekanik',
            'Peyzaj',
            'Ünite Bakımı',
            'Temapark Görsel',
        ];

        foreach ($units as $name) {
            Unit::create([
                'name'        => $name,
                'created_by'  => 1,
            ]);
        }
    }

    // truncate the units table
    public function truncate(): void
    {
        Unit::truncate();
    }
}
