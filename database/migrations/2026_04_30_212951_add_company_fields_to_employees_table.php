<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'company_name')) {
                $table->string('company_name', 255)
                    ->nullable()
                    ->after('profession');
            }

            if (!Schema::hasColumn('employees', 'company_id')) {
                $table->unsignedBigInteger('company_id')
                    ->nullable()
                    ->after('company_name');

                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_name', 'company_id']);
        });
    }
};
