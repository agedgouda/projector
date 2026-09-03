<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A 'spreadsheet_import' template (see AiTemplateController::rules()) has no prompts at
     * all — it holds import_config instead — so these can no longer be required for every row.
     */
    public function up(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->text('system_prompt')->nullable()->change();
            $table->text('user_prompt')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->text('system_prompt')->nullable(false)->change();
            $table->text('user_prompt')->nullable(false)->change();
        });
    }
};
