<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_RULE = <<<'RULE'
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
        RULE;

    private const NEW_RULE = <<<'RULE'
        The entire source text is a single task, typed by hand as a short command — not a
        document to search for multiple records. Extract exactly one record:
        - name: a short, clear title for the task (rewrite for clarity if the source is terse).
        - description: the fuller task description, if the source has more detail than fits in
          the title; otherwise the same as name.
        - assignee: if the text names a person responsible for the task and a "Known people"
          list is given below, match them against that list — even from a first name, nickname,
          or partial name — and output that person's full name exactly as listed, only when
          there's one clear, unambiguous match. If no list is given, or no listed person is a
          clear match, output null rather than guessing.
        - due_at: a due date, if one is mentioned (including relative dates like "tomorrow" or
          "Friday" — resolve them to an actual date), else null.
        - priority: "low", "medium", or "high" if urgency language is used (e.g. "urgent",
          "whenever", "ASAP"), else null.
        - tag: a category or label if one is clearly implied, else null.
        RULE;

    /**
     * CreateTaskFromSlackCommand now appends a dynamic "Known people on this project" list after
     * this rule (built from the project's org members/invitations, which resolveAssignee() itself
     * checks against) — this rewrite tells the AI how to use that list to turn "Penny" into
     * "Penny Fitzgerald" so the exact-match resolveAssignee() code downstream can actually find
     * her, rather than the AI just echoing back whatever partial name the text happened to use.
     * Only updates the row if it still has its original, un-customized text — an admin who's
     * already edited it in the Transformation Library keeps their own wording.
     */
    public function up(): void
    {
        DB::table('ai_templates')
            ->where('type', 'slack_task_extraction')
            ->where('user_prompt', self::OLD_RULE)
            ->update(['user_prompt' => self::NEW_RULE, 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')
            ->where('type', 'slack_task_extraction')
            ->where('user_prompt', self::NEW_RULE)
            ->update(['user_prompt' => self::OLD_RULE, 'updated_at' => now()]);
    }
};
