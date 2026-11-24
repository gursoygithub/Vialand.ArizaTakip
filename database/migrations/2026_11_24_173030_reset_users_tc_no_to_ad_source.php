<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * users.tc_no now links an account to its person, so it must only hold
     * the value AD gives (employeeID) at login. Clear whatever an earlier
     * project left there; each account refills it on its next login and
     * matches by e-mail until then.
     */
    public function up(): void
    {
        DB::table('users')->update(['tc_no' => null]);
    }

    public function down(): void {}
};
