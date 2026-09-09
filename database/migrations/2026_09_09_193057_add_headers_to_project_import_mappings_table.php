<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI classifier is not guaranteed to reproduce byte-identical field/pass decisions
     * between two calls, even for a file whose headers (and therefore real shape) haven't
     * changed — observed live: a trivial edit to one cell's text caused the classifier to
     * additionally propose a "task" pass alongside the already-working "event" pass, which then
     * blocked the whole re-import on re-confirming a mapping the project had, from a human's
     * perspective, already confirmed. Recording the exact headers a confirmed mapping applied to
     * lets FileImportProcessor recognize "I've seen this exact spreadsheet template before" and
     * reuse the previously-confirmed set of passes directly — skipping the AI call, and its
     * non-determinism, entirely for a recognized template.
     */
    public function up(): void
    {
        Schema::table('project_import_mappings', function (Blueprint $table) {
            $table->json('headers')->nullable()->after('mapping');
            $table->string('headers_hash')->nullable()->after('headers');
            $table->index(['project_id', 'headers_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_import_mappings', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'headers_hash']);
            $table->dropColumn(['headers', 'headers_hash']);
        });
    }
};
