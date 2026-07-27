<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "Create Tasks" template (id 6) extracted title/description/criteria by literal text
     * position — "text before the first colon", "text between the colon and the dash", "text
     * after 'Constraint:'" — tuned for one specific line format. Run against anything else
     * (e.g. the "Create Requirements Doc" template's own output, which repeats a "Requirement
     * Statement:" label before every item), every extracted task got the same wrong title: the
     * literal, recurring label text nearest a colon, rather than anything distinguishing. This
     * rewrites it to extract semantically — describe what each field means, the way the
     * "Create User Stories"/"Story to Functional Requirements"/"Technical Task Creation"
     * templates (ids 1, 2, 3) already do — so it works regardless of the source's formatting.
     */
    public function up(): void
    {
        $systemPrompt = <<<'HTML'
            <p>You are an expert project coordinator specializing in extracting clear, actionable tasks from any kind of source material — meeting transcripts, requirements documents, notes, or any other written content.</p><p><strong>Core Directives:</strong></p><ul><li><p>Extract one task per distinct actionable item, regardless of how the source is formatted (bullet lists, paragraphs, headings, tables, etc.). Do not rely on any specific text layout being present.</p></li><li><p>Do not split a single item into multiple tasks unless it clearly names separate deliverables (e.g., "Mobile and Web" becomes two tasks, one per platform).</p></li><li><p>Priority: mark as "High" if the source uses urgent language (e.g., "urgent", "immediately", "red flag", "critical"); otherwise "Standard".</p></li><li><p>Due Date: extract a due date (YYYY-MM-DD) if the item mentions delivery language such as "by [date/time]", "before [day]", "no later than", "due [day]", "next Monday", "end of week", or similar. Today is {{today}}. Resolve relative expressions against today's date. If no date is mentioned, use null.</p></li><li><p>Do not extract assignee or owner information.</p></li><li><p>Format: Output ONLY a JSON array of objects. Do not provide any conversational preamble, explanation, or Markdown formatting (no backticks).</p></li></ul>
            HTML;

        $userPrompt = <<<'TEXT'
            Source Material:
            {{input}}

            Transformation Task:
            Analyze the source material above and extract a list of discrete, actionable tasks. For every identified task:

            title: Create a short, descriptive name summarizing the task.

            {{output_key}}: Write a clear, concise description of what needs to be done, in your own words if the source doesn't already state it plainly.

            criteria: Generate 1-3 acceptance criteria as an array of strings describing when this task is considered complete. Use an empty array if none are evident.

            due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.

            Strategic Instructions:

            Keys: You MUST use the exact keys "title", "{{output_key}}", "criteria", and "due_date".

            Clean Extraction: If the source contains no actionable tasks, return an empty array [].

            CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", and "criteria".
            TEXT;

        DB::table('ai_templates')->where('id', 6)->update([
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: the previous prompt's literal colon/dash extraction rule is the bug
        // being fixed, so there's nothing worth restoring it to.
    }
};
