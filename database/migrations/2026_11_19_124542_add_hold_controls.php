<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds pause the SLA, so they are watched: every hold is recorded with
     * who started it and how long it lasted (working minutes), and the
     * supervisor is alerted once when a hold outlasts the team's limit
     * (hold_alert_minutes, working minutes, 0 = off). The supervisor can
     * switch these notices off, so the preference type is no longer a fixed
     * enum.
     */
    public function up(): void
    {
        Schema::create('ticket_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reason', 50);
            $table->text('note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('working_minutes')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'started_at']);
            $table->index(['ticket_id', 'ended_at']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedInteger('hold_alert_minutes')->default(480)->after('allow_self_assign');
        });

        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->string('notification_type', 50)->change();
        });
    }

    public function down(): void
    {
        DB::table('user_notification_preferences')->whereIn('notification_type', ['team_hold', 'team_hold_overdue'])->delete();

        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->enum('notification_type', [
                'ticket_assigned', 'ticket_participant', 'ticket_closed', 'ticket_reopened',
                'ticket_reassigned', 'sla_warning', 'sla_breach',
            ])->change();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('hold_alert_minutes');
        });

        Schema::dropIfExists('ticket_holds');
    }
};
