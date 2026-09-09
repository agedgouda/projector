<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_MODEL_CLASS = 'App\\Models\\SlackPendingImport';

    private const NEW_MODEL_CLASS = 'App\\Models\\PendingImport';

    /**
     * Generalizes the Slack-only "Needs Review" queue into a source-agnostic one, ahead of
     * Dropbox reusing the exact same queue (see App\Services\Import\FileImportProcessor) — the
     * table/model were never conceptually Slack-specific to begin with (a spreadsheet awaiting
     * mapping confirmation, or a document awaiting classification), just named after the only
     * source that existed at the time.
     */
    public function up(): void
    {
        Schema::rename('slack_pending_imports', 'pending_imports');

        Schema::table('pending_imports', function (Blueprint $table) {
            // Distinct from the existing source_type column, which already means something else
            // (the pending item's own content shape: 'spreadsheet' | 'text') — this is which
            // integration it arrived through.
            $table->string('source')->default('slack')->after('source_type');
        });

        // Spatie MediaLibrary has no morph map configured (see grep for Relation::morphMap — none
        // found), so it stores the model's raw class name in media.model_type. Without updating
        // existing rows here, every already-queued pending import's attached file would become
        // unreachable the moment the model class is renamed.
        DB::table('media')
            ->where('model_type', self::OLD_MODEL_CLASS)
            ->update(['model_type' => self::NEW_MODEL_CLASS]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('media')
            ->where('model_type', self::NEW_MODEL_CLASS)
            ->update(['model_type' => self::OLD_MODEL_CLASS]);

        Schema::table('pending_imports', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::rename('pending_imports', 'slack_pending_imports');
    }
};
