<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->onDelete('cascade');
            $table->foreignId('sub_area_id')->constrained('sub_areas')->onDelete('cascade');
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->integer('priority'); // TaskPriorityEnum (1, 2, 3, 4)
            $table->integer('deadline_minutes'); // SLA deadline in minutes (e.g., 240 for 4 hours, 1440 for 1 day)

            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['area_id', 'sub_area_id', 'unit_id', 'priority'], 'sla_policy_lookup');
            $table->unique(['area_id', 'sub_area_id', 'unit_id', 'priority', 'deleted_at'], 'unique_sla_policy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
