<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the breach of a still-open cycle was escalated to managers
     * (once per cycle).
     */
    public function up(): void
    {
        Schema::table('ticket_sla_cycles', function (Blueprint $table) {
            $table->dateTime('escalated_at')->nullable()->after('breach_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_sla_cycles', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
