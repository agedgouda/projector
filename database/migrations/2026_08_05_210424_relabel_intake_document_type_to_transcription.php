<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Relabels the "intake" document type from "Notes" to "Transcription" — the key itself is
     * untouched, only the user-facing label changes, so none of the logic that branches on
     * `config('workflow.intake_key')` is affected. Rewrites every protocol's document_schema
     * JSON that still says "Notes" for the intake entry, then regenerates the shared
     * document_type_definitions catalog from that corrected source (the catalog itself is
     * update-only on ProjectType save — see ProjectTypeObserver — so it never picks up a
     * changed label from JSON alone). Safe to re-run.
     */
    public function up(): void
    {
        $projectTypes = DB::table('project_types')->get(['id', 'document_schema']);

        foreach ($projectTypes as $projectType) {
            $schema = json_decode($projectType->document_schema ?? '[]', true);

            if (! is_array($schema)) {
                continue;
            }

            $changed = false;

            foreach ($schema as &$item) {
                if (is_array($item) && ($item['key'] ?? null) === 'intake' && ($item['label'] ?? null) === 'Notes') {
                    $item['label'] = 'Transcription';
                    $changed = true;
                }
            }
            unset($item);

            if ($changed) {
                DB::table('project_types')->where('id', $projectType->id)->update([
                    'document_schema' => json_encode($schema),
                ]);
            }
        }

        Artisan::call('app:backfill-normalized-project-type-schema');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: which rows previously said "Notes" (vs. something else already)
        // wasn't captured anywhere before this ran.
    }
};
