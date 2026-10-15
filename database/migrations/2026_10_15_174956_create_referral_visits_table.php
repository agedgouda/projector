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
        // Deliberately holds nothing that counts as personal data: no IP, no raw user agent,
        // no full referrer URL and no user_id — conversion is an aggregate flag only.
        Schema::create('referral_visits', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->index();
            $table->string('landing_path');
            $table->char('country', 2)->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('referrer_host')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_visits');
    }
};
