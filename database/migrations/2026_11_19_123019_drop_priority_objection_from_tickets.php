<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The priority is no longer changed after opening, so there is nothing
     * left to object to.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['priority_lowered_from', 'priority_objected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_lowered_from')->nullable()->after('priority');
            $table->timestamp('priority_objected_at')->nullable()->after('priority_lowered_from');
        });
    }
};
