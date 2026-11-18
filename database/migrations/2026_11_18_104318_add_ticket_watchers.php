<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People kept informed on a request (CC): they see the whole request,
     * may add notes and are told about the important steps. A request type
     * can make CC mandatory, for every request or only for some values of a
     * select field (stored in form_fields[].cc_required_options).
     */
    public function up(): void
    {
        Schema::create('ticket_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->boolean('cc_required')->default(false)->after('form_fields');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('cc_required');
        });

        Schema::dropIfExists('ticket_watchers');
    }
};
