<?php

namespace Database\Seeders;

use App\Enums\ManagerStatusEnum;
use App\Enums\UserStatusEnum;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Enums\ActiveStatusEnum;

class InitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUsername = env('APP_ADMIN_USERNAME', 'sa');
        $adminPassword = env('APP_ADMIN_PASSWORD', 'password');

        $checkUserTable = User::count();
        if ($checkUserTable == 0) {
            User::create([
                'name' => 'Super Admin',
                'username' => $adminUsername,
                'email' => env('APP_ADMIN_EMAIL', 'sa@app.com'),
                'password' => bcrypt($adminPassword),
                'status' => UserStatusEnum::ACTIVE,
                'created_by' => 1,
            ]);
        }

        Artisan::call('shield:generate', ['--all' => true]);

        $user = User::where('username', $adminUsername)->first();

        if ($user && !$user->hasRole('super_admin')) {

            $roleExists = \Spatie\Permission\Models\Role::where('name', 'super_admin')->exists();

            if ($roleExists) {
                $user->assignRole('super_admin');
            }
        }
    }
//    public function run(): void
//    {
//        $checkUserTable = User::count();
//        if ($checkUserTable == 0) {
//            User::create([
//                'employee_id' => uuid_create(UUID_TYPE_RANDOM),
//                'tc_no' => '00000000000',
//                'name' => 'Admin',
//                'email' => 'sa@app.com',
//                'password' => 'password',
//                'status' => ManagerStatusEnum::ACTIVE,
//                'created_by' => 1,
//            ]);
//        }
//    }
}
