<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Originally an append-only audit log (database/CLAUDE.md: "skip updated_at"),
 * but the comment-edit feature now mutates `note` in place — so we need a
 * column to record WHEN that happened so the timeline can show
 * "Son düzenleme: ..." for edited comments. Nullable for backfill safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_status_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('ticket_status_histories', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_status_histories', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_status_histories', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
