<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transcription (AssemblyAI) is billed per hour of audio, not per token — 'transcription'
     * rows record the audio length here and leave the token columns at 0.
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('audio_seconds')->default(0)->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('audio_seconds');
        });
    }
};
