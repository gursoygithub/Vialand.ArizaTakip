<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('performance_score', 5, 2)
                  ->nullable()->default(null)->change();
            $table->decimal('current_threshold', 5, 2)
                  ->nullable()->default(null)->change();
        });

        DB::statement("
            UPDATE employees
            SET performance_score = NULL,
                current_threshold = NULL
            WHERE performance_score = 0
              AND current_threshold = 0
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE employees
            SET performance_score = 0,
                current_threshold = 0
            WHERE performance_score IS NULL
              AND current_threshold IS NULL
        ");

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('performance_score', 5, 2)
                  ->nullable(false)->default(0)->change();
            $table->decimal('current_threshold', 5, 2)
                  ->nullable(false)->default(0)->change();
        });
    }
};
