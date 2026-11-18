<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the supervisor lowers a request's priority, the requester may
     * object once: priority_lowered_from keeps the priority before the
     * lowering, priority_objected_at marks an objection waiting for the
     * supervisor's decision.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_lowered_from')->nullable()->after('priority');
            $table->timestamp('priority_objected_at')->nullable()->after('priority_lowered_from');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['priority_lowered_from', 'priority_objected_at']);
        });
    }
};
