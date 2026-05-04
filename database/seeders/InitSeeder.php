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
            // employee_id and tc_no are NOT NULL on the users table.
            // LDAP-bound users get them populated via AttributeHandler on
            // first sync, but the bootstrap super-admin pre-dates LDAP and
            // needs deterministic synthetic placeholders. We use the same
            // `E######` convention as UserFactory so the row sorts cleanly
            // alongside test fixtures, with a fixed sentinel `000001` so
            // re-seeding finds the existing row rather than colliding on
            // the column's UNIQUE index (if/when one is added).
            User::create([
                'employee_id' => env('APP_ADMIN_EMPLOYEE_ID', 'E000001'),
                'tc_no'       => env('APP_ADMIN_TC_NO', '00000000000'),
                'name'        => 'Super Admin',
                'username'    => $adminUsername,
                'email'       => env('APP_ADMIN_EMAIL', 'sa@app.com'),
                'password'    => bcrypt($adminPassword),
                'status'      => UserStatusEnum::ACTIVE,
                'created_by'  => 1,
            ]);
        }

        // shield:generate prompts for `--panel` interactively when more than
        // one panel exists (or just to confirm). Under `db:seed
        // --no-interaction` that prompt throws NonInteractiveValidationException.
        // Pass the panel and the non-interaction flag explicitly so the
        // seeder is callable from CI / migrate:fresh without manual prep.
        //
        // --ignore-existing-policies is critical: without it, --all would
        // overwrite TicketPolicy / GroupPolicy / SlaPolicyPolicy with
        // auto-generated stubs that use the WRONG permission names
        // (`view_ticket` instead of our namespaced `ticket.view.all`).
        // The project root CLAUDE.md documents the manual `git checkout`
        // workaround for this; the flag obviates it entirely.
        Artisan::call('shield:generate', [
            '--all'                       => true,
            '--panel'                     => 'dashboard',
            '--no-interaction'            => true,
            '--ignore-existing-policies'  => true,
        ]);

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
