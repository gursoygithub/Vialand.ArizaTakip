<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('on_hold_since')->nullable()->after('closed_by');
            $table->unsignedInteger('total_on_hold_minutes')->default(0)->after('on_hold_since');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['on_hold_since', 'total_on_hold_minutes']);
        });
    }
};
