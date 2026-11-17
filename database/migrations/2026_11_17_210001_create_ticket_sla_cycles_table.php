<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One SLA cycle per (ticket, team arrival). A new cycle starts when the
     * ticket is opened, transferred to another team or reopened. The SLA
     * targets and calendar are snapshotted so later policy edits never
     * rewrite history.
     */
    public function up(): void
    {
        Schema::create('ticket_sla_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_no');
            $table->foreignId('team_id')->constrained('teams');
            $table->unsignedTinyInteger('priority');
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedTinyInteger('success_threshold')->default(80);
            $table->foreignId('work_calendar_id')->nullable()->constrained('work_calendars')->nullOnDelete();

            $table->dateTime('started_at');
            $table->dateTime('response_due_at');
            $table->dateTime('resolution_due_at');
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedSmallInteger('resolution_rejections')->default(0);

            $table->dateTime('paused_since')->nullable();
            $table->string('pause_kind', 30)->nullable();
            $table->unsignedInteger('paused_minutes')->default(0);

            $table->boolean('response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->dateTime('breached_at')->nullable();
            $table->string('breach_attribution', 20)->nullable();
            $table->foreignId('breach_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->dateTime('warning_sent_at')->nullable();
            $table->dateTime('breach_notified_at')->nullable();

            $table->string('outcome', 20)->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'cycle_no']);
            $table->index(['outcome', 'resolution_breached']);
            $table->index(['ended_at', 'resolution_due_at']);
        });

        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_sla_cycle_id')->constrained('ticket_sla_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('self_assigned')->default(false);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->decimal('remaining_ratio_at_start', 5, 4)->nullable();
            $table->timestamps();

            $table->index(['ticket_sla_cycle_id', 'started_at']);
            $table->index(['employee_id', 'ended_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('hold_reason', 30)->nullable()->after('on_hold_since');
            $table->string('closure_reason', 30)->nullable()->after('closed_by');
            $table->dateTime('auto_close_at')->nullable()->after('closure_reason');
            $table->dateTime('reopen_until')->nullable()->after('auto_close_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['hold_reason', 'closure_reason', 'auto_close_at', 'reopen_until']);
        });
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('ticket_sla_cycles');
    }
};
