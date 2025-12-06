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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('area_id')->constrained('areas')->onDelete('cascade');
            $table->foreignId('sub_area_id')->constrained('sub_areas')->onDelete('cascade');
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->text('unit_description')->nullable();
            $table->bigInteger('employee_id')->nullable();
            //$table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');

            $table->tinyInteger('type_id'); // from TaskTypeEnum
            $table->tinyInteger('status')->default(\App\Enums\TaskStatusEnum::PENDING->value);

            //$table->string('technician_id')->comment('Employee ID of the technician assigned to the task');
            $table->date('task_date')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('due_date')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
