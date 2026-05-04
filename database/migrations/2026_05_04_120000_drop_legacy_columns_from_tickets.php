<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the four legacy ticket columns that the Reform migration
     * superseded:
     *
     *   - unit_description (always empty in modern data)
     *   - completed_by     (replaced by closed_by)
     *   - due_date         (replaced by sla_deadline / closed_at)
     *   - sla_outcome      (replaced by sla_breached, the indexed boolean)
     *
     * All callers across the codebase have been migrated off these
     * columns; the Ticket model's $fillable / $casts / booted() hook /
     * completedBy() relation are removed alongside this migration.
     */
    public function up(): void
    {
        // The FK on completed_by has to come off before the column.
        // dropConstrainedForeignId handles both in one call across drivers.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
        });

        // Two standalone indexes — both created when the table was still
        // called `tasks` (the rename to `tickets` left the index names
        // alone on both MySQL and SQLite):
        //   - tasks_due_date_index (from 2026_02_26_205243_add_indexes_to_tasks_table)
        //   - tasks_sla_outcome_index (auto-named by ->index() chained on
        //     the sla_outcome column in 2026_02_26_145314)
        // Both drivers reject column drops while an index references the
        // column, so dropping these is required everywhere.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tasks_due_date_index');
            $table->dropIndex('tasks_sla_outcome_index');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['unit_description', 'due_date', 'sla_outcome']);
        });
    }

    /**
     * Rollback restores the schema. Note that the data these columns
     * held cannot be recovered from this migration alone — the rollback
     * is structural only.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->text('unit_description')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_date')->nullable();
            $table->string('sla_outcome')->nullable();
            $table->index('due_date');
        });
    }
};
