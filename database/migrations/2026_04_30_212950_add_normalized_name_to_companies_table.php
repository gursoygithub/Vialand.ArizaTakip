<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'normalized_name')) {
                $table->string('normalized_name', 255)
                    ->nullable()
                    ->after('name');
            }
        });

        DB::statement("
            UPDATE companies
            SET normalized_name = UPPER(TRIM(name))
            WHERE normalized_name IS NULL
        ");

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('normalized_name', 'companies_normalized_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }
};
