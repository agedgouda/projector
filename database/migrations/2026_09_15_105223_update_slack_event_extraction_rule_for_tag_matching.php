<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_RULE = <<<'RULE'
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
        RULE;

    private const NEW_RULE = <<<'RULE'
        The entire source text is a single event, typed by hand as a short command — not a
        document to search for multiple records. Extract exactly one record:
        - name: a short, clear title for the event (rewrite for clarity if the source is terse).
        - description: the fuller event description, if the source has more detail than fits in
          the title; otherwise the same as name.
        - start_date and due_at: the event's date(s), as YYYY-MM-DD (including relative dates
          like "tomorrow" or "next Thursday" — resolve them to an actual date). If the source
          gives only one date, use it for due_at and leave start_date null — the caller fills in
          the other end of the range itself when that happens. Null both if no date is mentioned.
        - tag: if the text clearly implies one of this project's existing tags, and a "Known
          tags" list is given below, output that tag's exact name as listed. If no list is
          given, or no listed tag is a clear match, output null — never invent a tag name that
          isn't in the list.
        RULE;

    /**
     * CreateEventFromSlackCommand now appends a dynamic "Known tags for this project" list after
     * this rule (built from the project's own Category records) — this rewrite tells the AI to
     * only ever pick a tag from that list, matching how resolveTag() downstream can only match
     * an existing category by exact name and never creates a new one. Only updates the row if
     * it still has its original, un-customized text — an admin who's already edited it in the
     * Transformation Library keeps their own wording.
     */
    public function up(): void
    {
        DB::table('ai_templates')
            ->where('type', 'slack_event_extraction')
            ->where('user_prompt', self::OLD_RULE)
            ->update(['user_prompt' => self::NEW_RULE, 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')
            ->where('type', 'slack_event_extraction')
            ->where('user_prompt', self::NEW_RULE)
            ->update(['user_prompt' => self::OLD_RULE, 'updated_at' => now()]);
    }
};
