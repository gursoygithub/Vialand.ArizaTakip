<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team tickets ask for a location only when their type requires it, and
     * never for a unit (the team replaces it). Foreign keys stay in place;
     * legacy tickets keep their values.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('area_id')->nullable()->change();
            $table->unsignedBigInteger('sub_area_id')->nullable()->change();
            $table->unsignedBigInteger('unit_id')->nullable()->change();
        });
    }

    /**
     * Not reversible once team tickets without a location exist.
     */
    public function down(): void
    {
        //
    }
};
