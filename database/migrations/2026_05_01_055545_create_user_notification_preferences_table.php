<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->enum('notification_type', [
                'ticket_assigned',
                'ticket_participant',
                'ticket_closed',
                'ticket_reopened',
                'ticket_reassigned',
                'sla_warning',
                'sla_breach',
            ]);
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);

            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'notification_type'], 'user_notif_pref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
