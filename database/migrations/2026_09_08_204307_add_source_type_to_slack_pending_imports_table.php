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
        // 'spreadsheet' (a task/event list — csv/xlsx/xls) or 'text' (a prose document — docx)
        // — tells PendingImportController::show() whether to re-parse the stored file with
        // TaskListImportService::analyze() or re-extract it with
        // DocumentFileExtractorService::extractDocxHtml() when a human opens it for review.
        // Defaulted rather than nullable so every row already queued before this column
        // existed is unambiguously treated as the only kind that existed until now.
        Schema::table('slack_pending_imports', function (Blueprint $table) {
            $table->string('source_type')->default('spreadsheet')->after('original_filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_pending_imports', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }
};
