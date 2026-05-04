<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
     *
     * Driver-aware FK drop: MySQL retains the original FK name when a
     * table is renamed (the `tasks → tickets` rename left FKs as
     * `tasks_*_foreign`). `dropConstrainedForeignId('completed_by')`
     * computes the conventional name from the *current* table —
     * `tickets_completed_by_foreign` — which doesn't exist on MySQL
     * databases that lived through the rename. We query
     * information_schema for the actual constraint name and drop it
     * explicitly. On SQLite the FK is enforced at the engine level
     * without a stable name, and Laravel rebuilds the table when
     * dropping a column anyway, so an explicit dropForeign there is
     * unnecessary (and a no-op-style drop with the wrong name would
     * fail the migration).
     */
    public function up(): void
    {
        if (Schema::hasColumn('tickets', 'completed_by')) {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql') {
                // Drop the FK by its actual name (legacy `tasks_*` after the
                // rename), then drop the column. dropConstrainedForeignId is
                // unsafe here because it computes the name from the current
                // table — `tickets_completed_by_foreign` — which doesn't exist.
                $fk = DB::selectOne(
                    "SELECT CONSTRAINT_NAME AS name
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'tickets'
                       AND COLUMN_NAME = 'completed_by'
                       AND REFERENCED_TABLE_NAME IS NOT NULL"
                );

                if ($fk) {
                    Schema::table('tickets', function (Blueprint $table) use ($fk) {
                        $table->dropForeign($fk->name);
                    });
                }

                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropColumn('completed_by');
                });
            } else {
                // SQLite (and other rebuild-on-alter drivers): the schema
                // builder recreates the table when dropping a foreign key,
                // so the conventional name is sufficient and the column
                // drop has to ride alongside the FK drop in the same call —
                // a bare dropColumn on a column with a live FK reference
                // raises "unknown column ... in foreign key definition"
                // because SQLite refuses to leave a dangling FK.
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('completed_by');
                });
            }
        }

        // Two standalone indexes — both created when the table was still
        // called `tasks` (the rename to `tickets` left the index names
        // alone on both MySQL and SQLite):
        //   - tasks_due_date_index (from 2026_02_26_205243_add_indexes_to_tasks_table)
        //   - tasks_sla_outcome_index (auto-named by ->index() chained on
        //     the sla_outcome column in 2026_02_26_145314)
        // Both drivers reject column drops while an index references the
        // column. Try-with-existence-check so a partially-applied or
        // re-run migration on a DB whose indexes were already cleaned
        // doesn't fail.
        if (Schema::hasColumn('tickets', 'due_date') && $this->indexExists('tickets', 'tasks_due_date_index')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tasks_due_date_index');
            });
        }

        if (Schema::hasColumn('tickets', 'sla_outcome') && $this->indexExists('tickets', 'tasks_sla_outcome_index')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tasks_sla_outcome_index');
            });
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['unit_description', 'due_date', 'sla_outcome'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * Rollback restores the schema. Note that the data these columns
     * held cannot be recovered from this migration alone — the rollback
     * is structural only. The FK created here will be named
     * `tickets_completed_by_foreign` (the conventional name) regardless
     * of what existed before, since the legacy `tasks_*` name was a
     * historical artifact of the table rename, not a contract.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'unit_description')) {
                $table->text('unit_description')->nullable();
            }
            if (!Schema::hasColumn('tickets', 'completed_by')) {
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('tickets', 'due_date')) {
                $table->dateTime('due_date')->nullable();
            }
            if (!Schema::hasColumn('tickets', 'sla_outcome')) {
                $table->string('sla_outcome')->nullable();
            }
        });

        if (Schema::hasColumn('tickets', 'due_date') && !$this->indexExists('tickets', 'tickets_due_date_index')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->index('due_date');
            });
        }
    }

    /**
     * Cross-driver index existence probe. MySQL has information_schema;
     * SQLite has sqlite_master. The Laravel schema builder has no
     * portable hasIndex() helper as of 12.x.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                 LIMIT 1",
                [$table, $indexName]
            );
            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $row = DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ? AND tbl_name = ?",
                [$indexName, $table]
            );
            return $row !== null;
        }

        // Unknown driver — assume present so the caller still attempts
        // the drop (preserving prior behaviour rather than silently
        // skipping).
        return true;
    }
};
