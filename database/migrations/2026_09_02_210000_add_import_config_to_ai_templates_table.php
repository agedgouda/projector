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
        Schema::table('ai_templates', function (Blueprint $table) {
            // Holds the passes[] config for a 'spreadsheet_import' template — one entry per
            // detected record type (list_type + column mapping), the same mapping shape
            // StoreTaskListImportRequest already validates for a single-type import. Unused
            // (null) for every other template type, which keep using system_prompt/user_prompt.
            $table->json('import_config')->nullable()->after('user_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->dropColumn('import_config');
        });
    }
};
