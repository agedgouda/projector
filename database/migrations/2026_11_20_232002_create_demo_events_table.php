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
        // What a demo visitor did inside the sandbox: one row per page visit or action, recorded
        // by route name only (no content, ids or parameters). Outlives the sandbox itself.
        Schema::create('demo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_session_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('method', 7);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['demo_session_id', 'created_at']);
            $table->index('event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_events');
    }
};
