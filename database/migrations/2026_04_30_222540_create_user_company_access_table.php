<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_company_access', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('granted_by')->nullable();

            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('company_id')
                ->references('id')->on('companies')
                ->cascadeOnDelete();

            $table->foreign('granted_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['user_id', 'company_id'], 'user_company_access_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company_access');
    }
};
