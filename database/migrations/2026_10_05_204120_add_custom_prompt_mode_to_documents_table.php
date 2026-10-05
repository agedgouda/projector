<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Left null for every existing row — App\Enums\CustomPromptMode treats a missing mode as
     * Replace, which is how every custom prompt already behaved, so nothing already imported
     * processes any differently.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('custom_prompt_mode')->nullable()->after('custom_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('custom_prompt_mode');
        });
    }
};
