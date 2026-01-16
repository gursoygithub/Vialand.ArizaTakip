<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
            $table->uuid('ldap_guid')->nullable()->unique()->after('password'); // UUID olarak
            $table->text('ldap_dn')->nullable()->after('ldap_guid'); // DN uzun olabilir
            $table->json('ldap_groups')->nullable()->after('ldap_dn');
            $table->timestamp('last_ldap_sync')->nullable()->after('ldap_groups');

            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('department')->nullable()->after('last_name');
            $table->string('office')->nullable()->after('department');
            $table->text('address')->nullable()->after('office');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'ldap_guid',
                'ldap_dn',
                'ldap_groups',
                'last_ldap_sync',
                'first_name',
                'last_name',
                'department',
                'office',
                'address',
            ]);
        });
    }
};
