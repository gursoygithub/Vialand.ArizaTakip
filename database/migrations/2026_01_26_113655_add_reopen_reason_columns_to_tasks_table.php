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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('reopen_reason')->nullable()->after('resolution_notes');
            $table->unsignedBigInteger('reopened_by')->nullable()->after('reopen_reason');
            $table->dateTime('reopened_at')->nullable()->after('reopened_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('reopen_reason');
            $table->dropColumn('reopened_by');
            $table->dropColumn('reopened_at');
        });
    }
};
