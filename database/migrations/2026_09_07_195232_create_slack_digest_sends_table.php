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
        // Idempotency record for the daily Slack digest — one row per (org, admin, local
        // calendar day) it was actually sent for, so a scheduler run that overlaps an hour
        // boundary (or reruns after a crash) can't double-send the same admin's digest twice
        // in one day. sent_date is the admin's own local date, not UTC, since the whole point
        // of this feature is "once per day in the recipient's own morning".
        Schema::create('slack_digest_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('sent_date');
            $table->unique(['organization_id', 'user_id', 'sent_date']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_digest_sends');
    }
};
