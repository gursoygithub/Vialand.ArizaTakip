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
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('tc_no', 11);
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->tinyInteger('status')->default(\App\Enums\ManagerStatusEnum::ACTIVE);

            $table->string('title')->nullable(); // unvan
            $table->string('profession')->nullable(); // meslek

            $table->tinyInteger('created_by');
            $table->tinyInteger('updated_by')->nullable();
            $table->tinyInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technicians');
    }
};
