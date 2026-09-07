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
        Schema::create('slack_user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slack_team_id');
            $table->string('slack_user_id');
            $table->string('slack_username')->nullable();
            $table->unique(['slack_team_id', 'slack_user_id']);
            $table->unique(['user_id', 'slack_team_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_user_identities');
    }
};
