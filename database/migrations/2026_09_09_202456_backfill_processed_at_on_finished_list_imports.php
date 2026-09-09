<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * task_list_import/event_list_import documents were never stamped with processed_at once
     * ImportTaskList finished (or failed) — see the App\Jobs\ImportTaskList change that
     * accompanies this migration. Since these types are never a "task" per
     * DocumentTypeDefinition's catalog, DocumentObserver::creating() never stamped it either, so
     * it sat null forever regardless of outcome, making TraceabilityRow.vue/TaskRowContent.vue's
     * shared isProcessing check (`processed_at === null`) show every one of these — success or
     * failure — as permanently "Processing...". This backfills every already-finished one (its
     * own metadata.status already says otherwise) using updated_at as the best available proxy
     * for "when it actually finished", since finish()/failed() always bump it via update().
     */
    public function up(): void
    {
        DB::table('documents')
            ->whereIn('type', ['task_list_import', 'event_list_import'])
            ->whereNull('processed_at')
            ->update(['processed_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: which rows this actually touched (vs. already had processed_at set
        // for some other reason) wasn't captured anywhere before this ran.
    }
};
