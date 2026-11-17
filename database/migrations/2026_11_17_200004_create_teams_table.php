<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teams serve exactly one ticket type. Supervisor and members can come
     * from any company. A NULL work_calendar_id means the SLA clock runs 7/24.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_type_id')->constrained('ticket_types');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('supervisor_employee_id')->constrained('employees');
            $table->foreignId('work_calendar_id')->nullable()->constrained('work_calendars')->nullOnDelete();
            $table->boolean('allow_self_assign')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ticket_type_id', 'is_active']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'employee_id']);
        });

        Schema::create('team_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority');
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedTinyInteger('success_threshold')->default(80);
            $table->timestamps();

            $table->unique(['team_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_sla_policies');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
