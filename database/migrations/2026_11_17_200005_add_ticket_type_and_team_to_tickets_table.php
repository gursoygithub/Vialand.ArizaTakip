<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link tickets to the new type/team model. Nullable so existing tickets
     * (created through the legacy area/unit/group flow) stay valid until
     * the lifecycle rewrite moves ticket creation onto types and teams.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('ticket_type_id')->nullable()->after('type_id')->constrained('ticket_types');
            $table->foreignId('team_id')->nullable()->after('group_id')->constrained('teams');
            $table->json('custom_fields')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_type_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn('custom_fields');
        });
    }
};
