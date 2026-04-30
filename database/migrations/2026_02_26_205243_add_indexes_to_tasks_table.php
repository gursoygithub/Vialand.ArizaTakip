<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Hata veren employee_id, sla_outcome ve status'u sildik.
            // Sadece tarih bazlı sorguları hızlandıracak olanları deniyoruz:
            $table->index('due_date');
            $table->index('created_at');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Email indexi personeli hızlı bulmak için çok kritiktir
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['due_date', 'created_at']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['email']);
        });
    }
};