<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable working calendars ("7/24", "Hafta içi 08:00-18:00") that a
     * team picks so its SLA clock only runs in working time.
     * working_hours: {"1": {"start": "08:00", "end": "18:00"}, ...}, keys are
     * ISO weekdays (1 = Monday). A missing day means "closed".
     */
    public function up(): void
    {
        Schema::create('work_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_24_7')->default(false);
            $table->json('working_hours')->nullable();
            $table->boolean('observes_holidays')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_calendars');
    }
};
