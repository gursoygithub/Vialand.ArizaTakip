<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: the requester's satisfaction score per cycle, and SLA
     * exemption requests (decision 8) — an approved exemption takes the
     * cycle out of every performance figure.
     */
    public function up(): void
    {
        Schema::table('ticket_sla_cycles', function (Blueprint $table) {
            $table->unsignedTinyInteger('satisfaction_score')->nullable()->after('resolution_rejections');
            $table->text('satisfaction_comment')->nullable()->after('satisfaction_score');
            $table->dateTime('rated_at')->nullable()->after('satisfaction_comment');
            $table->boolean('exempted')->default(false)->after('breach_employee_id');
        });

        Schema::create('sla_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_sla_cycle_id')->constrained('ticket_sla_cycles')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason', 30);
            $table->text('note');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_exemptions');

        Schema::table('ticket_sla_cycles', function (Blueprint $table) {
            $table->dropColumn(['satisfaction_score', 'satisfaction_comment', 'rated_at', 'exempted']);
        });
    }
};
