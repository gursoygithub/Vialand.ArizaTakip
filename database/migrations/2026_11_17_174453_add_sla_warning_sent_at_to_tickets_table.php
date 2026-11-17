<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when the 80% SLA warning was last sent, so CheckSlaBreaches
     * no longer depends on a `notifications` row existing for its daily
     * de-dupe (no row is written when every recipient muted the bell).
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_warning_sent_at')->nullable()->after('sla_breached');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sla_warning_sent_at');
        });
    }
};
