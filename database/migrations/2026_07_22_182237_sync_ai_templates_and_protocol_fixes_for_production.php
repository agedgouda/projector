<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replays a batch of manual data edits made directly against a local copy of production
     * (AI template cleanup + fixing three protocols whose first workflow step still pointed
     * from "intake" instead of "action_items", a leftover from before that convention existed)
     * so they land on the real production database too. Safe to re-run — every step is a
     * no-op if the row is already gone/already matches.
     */
    public function up(): void
    {
        // Templates confirmed unused by any protocol during a manual review.
        DB::table('ai_templates')->whereIn('id', [4, 7])->delete();

        // Renamed by hand during the same review — set every surviving template's name to
        // match what it was renamed to locally.
        $names = [
            1 => 'Create User Stories',
            2 => 'Story to Functional Requirements',
            3 => 'Technical Task Creation',
            5 => 'Notes to Action Items',
            6 => 'Create Tasks',
            8 => 'Status Meeting: Action Item Extraction',
            9 => 'Create Requirements Doc',
        ];

        foreach ($names as $id => $name) {
            DB::table('ai_templates')->where('id', $id)->update(['name' => $name]);
        }

        // These three protocols' first real step was still "intake -> X" (dead, since every
        // Notes document now auto-converts to Action Items universally) instead of
        // "action_items -> X" — the same fix already applied everywhere else.
        $workflowFixes = [
            '019bd81a-cac6-71db-bafb-9f4dc4a6b330' => [ // Software
                ['to_key' => 'user_story', 'from_key' => 'action_items', 'ai_template_id' => 1],
                ['to_key' => 'functional_requirement', 'from_key' => 'user_story', 'ai_template_id' => 2],
                ['to_key' => 'technical_task', 'from_key' => 'functional_requirement', 'ai_template_id' => 3],
                ['to_key' => 'requirement', 'from_key' => 'requirements', 'ai_template_id' => 9],
            ],
            '019d4729-3708-7228-92a2-22abbee3d147' => [ // Book Project
                ['to_key' => 'task', 'from_key' => 'action_items', 'ai_template_id' => 6],
            ],
            '019c2b0d-909f-706c-b119-1a3a98988822' => [ // To Do List
                ['to_key' => 'task', 'from_key' => 'action_items', 'ai_template_id' => 6],
                ['to_key' => 'requirement', 'from_key' => 'requirements', 'ai_template_id' => 9],
            ],
        ];

        foreach ($workflowFixes as $projectTypeId => $workflow) {
            DB::table('project_types')
                ->where('id', $projectTypeId)
                ->update(['workflow' => json_encode($workflow)]);
        }

        // Regenerate document_type_definitions/workflow_steps from the corrected source above.
        Artisan::call('app:backfill-normalized-project-type-schema');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: the deleted templates' original rows and the pre-rename names
        // weren't captured anywhere before this ran.
    }
};
