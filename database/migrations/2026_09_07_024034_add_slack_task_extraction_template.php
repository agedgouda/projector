<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the global template that controls how CreateTaskFromSlackCommand interprets a
     * single /task command's text — previously a hardcoded PHP constant, now editable by
     * super-admins from the Transformation Library like the sibling 'text_extraction'/
     * 'text_extraction_classification' templates it's looked up alongside.
     *
     * Unlike those two, this type has no {{placeholders}} of its own — the whole user_prompt
     * *is* the extraction_rule string, substituted as-is into 'text_extraction'`s own
     * {{extraction_rule}} placeholder by TextExtractionService::extract(). system_prompt is
     * unused by the code (AiTemplateController's form requires it be non-empty for any
     * prompt-shaped type), so it just documents that for whoever edits this next.
     */
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Slack /task Extraction Rule',
            'description' => "Controls how Projector interprets a single Slack /task command's text. Not a full AI prompt — only the User Prompt field is used, as the extraction_rule for the shared Text Extraction template. The System Prompt field is unused; edit it or leave it as-is.",
            'type' => 'slack_task_extraction',
            'user_prompt' => <<<'RULE'
                The entire source text is a single task, typed by hand as a short command — not a
                document to search for multiple records. Extract exactly one record:
                - name: a short, clear title for the task (rewrite for clarity if the source is terse).
                - description: the fuller task description, if the source has more detail than fits in
                  the title; otherwise the same as name.
                - assignee: a person's name mentioned as responsible for the task, if any, else null.
                - due_at: a due date, if one is mentioned (including relative dates like "tomorrow" or
                  "Friday" — resolve them to an actual date), else null.
                - priority: "low", "medium", or "high" if urgency language is used (e.g. "urgent",
                  "whenever", "ASAP"), else null.
                - tag: a category or label if one is clearly implied, else null.
                RULE,
            'system_prompt' => 'Not used — see description.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')->where('type', 'slack_task_extraction')->delete();
    }
};
