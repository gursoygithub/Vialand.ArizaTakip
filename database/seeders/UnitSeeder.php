<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // Unit::creating overrides $created_by with auth()->id() — and the
        // explicit `'created_by' => 1` below is overwritten by that hook.
        // Under `db:seed` there is no authenticated user, so the hook
        // writes NULL and trips the NOT NULL constraint. Log in as the
        // super-admin (falling back to the first user) for the duration
        // of the seed so the booted hook resolves to a real id, and
        // restore the prior auth state afterwards in case this seeder is
        // ever invoked from a request context.
        $previous = Auth::user();
        $admin = User::where('username', env('APP_ADMIN_USERNAME', 'sa'))->first()
            ?? User::orderBy('id')->first();

        if ($admin) {
            Auth::login($admin);
        }

        try {
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
        } finally {
            if ($previous) {
                Auth::login($previous);
            } elseif ($admin) {
                Auth::logout();
            }
        }
    }

    // truncate the units table
    public function truncate(): void
    {
        Unit::truncate();
    }
}
