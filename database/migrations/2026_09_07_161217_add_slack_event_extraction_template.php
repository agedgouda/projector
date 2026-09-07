<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the global template that controls how CreateEventFromSlackCommand interprets a
     * single /events command's text — the /events counterpart to 'slack_task_extraction'.
     *
     * Like that one, this type has no {{placeholders}} of its own — the whole user_prompt *is*
     * the extraction_rule string, substituted as-is into 'text_extraction'`s own
     * {{extraction_rule}} placeholder by TextExtractionService::extract(). system_prompt is
     * unused by the code, so it just documents that for whoever edits this next.
     */
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Slack /events Extraction Rule',
            'description' => "Controls how Projector interprets a single Slack /events command's text. Not a full AI prompt — only the User Prompt field is used, as the extraction_rule for the shared Text Extraction template. The System Prompt field is unused; edit it or leave it as-is.",
            'type' => 'slack_event_extraction',
            'user_prompt' => <<<'RULE'
                The entire source text is a single event, typed by hand as a short command — not a
                document to search for multiple records. Extract exactly one record:
                - name: a short, clear title for the event (rewrite for clarity if the source is terse).
                - description: the fuller event description, if the source has more detail than fits in
                  the title; otherwise the same as name.
                - start_date and due_at: the event's date(s), as YYYY-MM-DD (including relative dates
                  like "tomorrow" or "next Thursday" — resolve them to an actual date). If the source
                  gives only one date, use it for due_at and leave start_date null — the caller fills in
                  the other end of the range itself when that happens. Null both if no date is mentioned.
                - tag: a category or label for the event if one is clearly implied (e.g. "team offsite",
                  "client meeting", "deadline"), else null.
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
        DB::table('ai_templates')->where('type', 'slack_event_extraction')->delete();
    }
};
