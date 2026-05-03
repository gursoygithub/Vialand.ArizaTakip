<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->text('token');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            // FCM tokens can be longer than the default index limit on MySQL,
            // so we hash a uniqueness column instead of indexing the full text.
            $table->string('token_hash', 64);
            $table->unique(['user_id', 'token_hash'], 'fcm_tokens_user_token_unique');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
