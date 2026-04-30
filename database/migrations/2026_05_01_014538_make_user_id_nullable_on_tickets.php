<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tickets.user_id` was the pre-reform creator FK. The reform added
 * `created_by` for the same purpose, and nothing in the current code base
 * reads or writes `user_id` — keeping it NOT NULL only blocks inserts that
 * don't bother setting the now-redundant column. Relaxing it to nullable
 * preserves legacy rows while letting new ticket creates proceed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used by the test suite) doesn't have information_schema and
        // doesn't enforce the user_id NOT NULL the same way — TicketFactory
        // already provides user_id on test fixtures, so the column constraint
        // is a no-op there. Apply the relaxation only on MySQL where prod
        // ticket inserts were failing.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Drop the FK first if it still exists. The tasks→tickets rename
        // migration may have already dropped it implicitly on some envs,
        // so this is a best-effort. MySQL won't let us alter a referenced
        // column without dropping the constraint first.
        if ($this->hasForeignKey('tickets', 'tickets_user_id_foreign')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        DB::statement('ALTER TABLE tickets MODIFY user_id BIGINT UNSIGNED NULL DEFAULT NULL');

        // Re-attach the FK with relaxed nullability semantics — only if it's
        // not already there (idempotent on re-runs).
        if (!$this->hasForeignKey('tickets', 'tickets_user_id_foreign')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->exists();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        // Tighten only if no nulls exist; otherwise leave nullable. This
        // matches "always create a new migration" guidance — we don't try
        // to retroactively backfill.
        $hasNull = DB::table('tickets')->whereNull('user_id')->exists();
        if (!$hasNull) {
            DB::statement('ALTER TABLE tickets MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};
