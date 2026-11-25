<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each team keeps its own list of topics; whoever resolves a request
     * picks one. Removed topics are soft deleted so past requests keep their
     * topic. A request type can also ask the resolver for the location.
     */
    public function up(): void
    {
        Schema::create('team_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('topic_id')->nullable()->after('team_id')->constrained('team_topics')->nullOnDelete();
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->string('resolve_location_requirement', 20)->default('hidden')->after('location_requirement');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('resolve_location_requirement');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_id');
        });

        Schema::dropIfExists('team_topics');
    }
};
