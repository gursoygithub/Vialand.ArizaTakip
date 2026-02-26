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
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->integer('success_threshold')->default(80)
                ->after('deadline_minutes')
                ->comment('Başarı eşiği (yüzde olarak)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            //
        });
    }
};
