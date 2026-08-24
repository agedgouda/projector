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
        Schema::table('documents', function (Blueprint $table) {
            // Optional — most tasks are due-date-only. A task with both start_at and a due
            // date renders as a spanning "event" on the calendar instead of a single-day
            // marker (see ProjectCalendar.vue); one with only a due date stays exactly as
            // it's always worked.
            $table->timestamp('start_at')->nullable()->after('due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('start_at');
        });
    }
};
