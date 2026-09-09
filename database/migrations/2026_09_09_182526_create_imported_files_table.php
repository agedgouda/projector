<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A content hash recorded every time FileImportProcessor actually does something lasting
     * with a file (auto-imports it, files it directly via a forced type, or parks it as a
     * PendingImport) — checked before processing so the same file dropped in a bound Slack
     * channel or Dropbox folder twice (a re-share, a sync glitch) doesn't produce duplicate
     * tasks/events or duplicate review-queue entries. Scoped per project, not globally: the same
     * file legitimately belongs to more than one project.
     */
    public function up(): void
    {
        Schema::create('imported_files', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->string('original_filename');
            $table->string('source');
            $table->timestamps();

            $table->unique(['project_id', 'content_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_files');
    }
};
