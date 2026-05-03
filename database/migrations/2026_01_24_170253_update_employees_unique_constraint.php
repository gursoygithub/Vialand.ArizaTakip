<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent: the original employees migration already declared
     * `employee_id` as unique. This migration historically re-asserted the
     * constraint; it is wrapped in a try/catch so it is a no-op when the
     * index already exists (e.g. on SQLite test databases).
     */
    public function up(): void
    {
        try {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique('employee_id');
            });
        } catch (\Throwable $e) {
            // Index already exists — nothing to do.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique(['employee_id']);
            });
        } catch (\Throwable $e) {
            // Index does not exist — nothing to do.
        }
    }
};
