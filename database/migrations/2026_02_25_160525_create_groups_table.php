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
        Schema::create('groups', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->foreignId('company_id')->constrained()->onDelete('cascade')
                ->comment('company of the group');
            $table->foreignId('area_id')->constrained()->onDelete('cascade')
                ->comment('area of the group');
            $table->foreignId('unit_id')->constrained()->onDelete('cascade')
                ->comment('unit of the group members');
            $table->foreignId('employee_id')->constrained()->onDelete('cascade')
                ->comment('manager of the group');
            $table->integer('status')->default(\App\Enums\ActiveStatusEnum::ACTIVE->value);

            $table->unsignedTinyInteger('created_by')->nullable();
            $table->unsignedTinyInteger('updated_by')->nullable();
            $table->unsignedTinyInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'unit_id', 'employee_id', 'deleted_at'], 'unique_group_manager');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
