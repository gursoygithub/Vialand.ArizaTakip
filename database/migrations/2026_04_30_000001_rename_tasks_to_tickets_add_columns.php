<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('tasks', 'tickets');

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('ticket_no', 20)->nullable()->unique()->after('id');
            $table->dateTime('sla_deadline')->nullable()->after('sla_outcome');
            $table->boolean('sla_breached')->default(false)->after('sla_deadline');
            $table->dateTime('assigned_at')->nullable()->after('sla_breached');
            $table->dateTime('resolved_at')->nullable()->after('assigned_at');
            $table->dateTime('closed_at')->nullable()->after('resolved_at');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropColumn([
                'ticket_no', 'sla_deadline', 'sla_breached',
                'assigned_at', 'resolved_at', 'closed_at', 'closed_by',
            ]);
        });

        Schema::rename('tickets', 'tasks');
    }
};
