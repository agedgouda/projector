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
        // One row per guest who lands on /demo, kept permanently for funnel analytics. Like
        // referral_visits it holds nothing that counts as personal data: no IP, no raw user
        // agent, no full referrer URL and no user_id — registration is a timestamp only.
        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ref')->nullable()->index();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('referrer_host')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_sessions');
    }
};
