<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_mutes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');

            $table->timestamps();

            $table->foreign('ticket_id')
                ->references('id')->on('tickets')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['ticket_id', 'user_id'], 'ticket_mutes_ticket_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_mutes');
    }
};
