<?php

namespace Database\Seeders;

use App\Enums\ManagerStatusEnum;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Enums\ActiveStatusEnum;

class InitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $checkUserTable = User::count();
        if ($checkUserTable == 0) {
            User::create([
                'employee_id' => uuid_create(UUID_TYPE_RANDOM),
                'tc_no' => '00000000000',
                'name' => 'Admin',
                'email' => 'sa@app.com',
                'password' => 'password',
                'status' => ManagerStatusEnum::ACTIVE,
                'created_by' => 1,
            ]);
        }
    }
}
