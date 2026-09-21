<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the global template that controls how ReportRequestParser turns a /report command's
     * plain-English text into report filters — editable by super-admins in the Transformation
     * Library instead of a fixed PHP constant.
     *
     * Unlike the /task and /events rules, this is a real system_prompt/user_prompt pair. The
     * user_prompt is filled in per request through the placeholders listed in its description;
     * the shape of the AI's answer (which filters it can return) is fixed by the code, so
     * editing these prompts changes how requests are read, not what can be filtered on.
     */
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Slack /report Request Interpretation',
            'description' => "Controls how Projector reads a Slack /report command's plain-English text into report filters (assignee, status, priority, tags, sub-project, dates, due vs. done, file format). The System Prompt holds the interpretation rules; the User Prompt is filled in for each request using these placeholders: {{today}} (today's date and weekday in the requester's timezone), {{people}}, {{statuses}}, {{tags}}, {{projects}} (the lists the AI may choose from), and {{request}} (the text typed after /report). The filters the AI can return are fixed by Projector — editing these prompts changes how requests are understood, not what can be filtered on.",
            'type' => 'slack_report_request',
            'system_prompt' => <<<'PROMPT'
                You turn a person's plain-English request for a task report into structured filters. The
                request comes from a chat message, so it may be terse or informal.

                Rules:
                - Only use values from the lists you are given (people, statuses, tags, projects). Never
                  invent one. If the request names something that is not in a list, still output what was
                  written, exactly as written, so it can be reported back as not found.
                - Leave a filter null (or an empty list) unless the request actually asks for it.
                - Dates: resolve relative dates ("next week", "this month", "last Friday") against today's
                  date, given below, into concrete YYYY-MM-DD values. A range is inclusive on both ends. A
                  week runs Monday to Sunday. A whole month or quarter means its first through last day.
                  A single day is the same date for both from and to. "Overdue" means to = yesterday,
                  combined with every status that is not the done status.
                - date_kind: "done" if the request is about when tasks were completed/finished/closed
                  ("done last week", "completed in March"), otherwise "due" (also the default when a date
                  is given without saying which).
                - assignees: use the person's exact name from the list. Use "me" when the request says "my"
                  or "mine" or "I". Use "Unassigned" for tasks with nobody assigned.
                - statuses: use the status key from the list (the value before the colon).
                - format: "excel", "csv", or "pdf" if the request asks for one, otherwise null.
                PROMPT,
            'user_prompt' => <<<'PROMPT'
                Today: {{today}}
                People: {{people}}
                Statuses (key: label): {{statuses}}
                Tags: {{tags}}
                Projects: {{projects}}

                Request: {{request}}
                PROMPT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')->where('type', 'slack_report_request')->delete();
    }
};
