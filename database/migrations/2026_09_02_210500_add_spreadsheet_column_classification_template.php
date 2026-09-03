<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the global (organization_id null) template SpreadsheetClassificationService looks
     * up by type — the same "one global row, looked up via AiTemplate::where('type', ...)"
     * pattern OrgAiService::extractActionItems() already uses for 'org_extraction'.
     */
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Spreadsheet Column Classification',
            'type' => 'spreadsheet_column_classification',
            'system_prompt' => 'You are an expert at looking at messy, real-world spreadsheets that people already keep by hand, and figuring out what structured data they actually contain. The system you support keeps two completely separate kinds of records — Events (something with a name and a date or date range) and Tasks (a discrete deliverable or action item with its own due date) — and never merges the two into one record.

Core Directives:

One Pass Per Record Type: A single sheet is often really two kinds of data folded into one table — one set of columns describing an event (name, location, start/end dates), and, separately, a column that actually describes a follow-up deliverable or task (e.g. "Assets Needed", "Deliverable", "Action Item"). Propose one "pass" per record type you find real evidence for in the sample rows: usually exactly one "event" pass, plus a "task" pass only when a column\'s actual sample values look like genuine deliverables or action items — not just an always-empty or clearly unrelated column. If the sheet only contains one kind of data, return exactly one pass.

Exact Header Text: For each pass, map these fields — name, priority, task_status, due_at, assignee, start_date, description, tag — to a column header from the sheet, copied EXACTLY as it appears in the given header list, character for character. Use null for any field nothing in the sheet supplies. Never invent a header that is not in the given list.

Name Is Everything: "name" is the most important field in every pass — it titles the created record, and a row with nothing in the mapped name column is skipped entirely. Choose the column whose sample values actually look like short, human-readable titles, not a category, date, or boilerplate column.

Event Pass Fields: Only name, due_at, start_date, description, and tag are meaningful for an "event" pass — always leave priority, task_status, and assignee null there.

Task Pass Dates: When the sheet has no dedicated due-date column for the deliverable itself, reuse whichever date column marks the related event\'s own end or due date as the task pass\'s due_at — a task with no due date can still be created, it just will not appear on any calendar.

No Invention: Never propose a pass, or a field mapping, the sample rows do not actually support. A plausible-looking but consistently empty column is not evidence.

Format: Output ONLY the structured passes described in the response schema. Do not provide conversational preamble or Markdown formatting.',
            'user_prompt' => 'Here is a spreadsheet\'s header row and a sample of its data rows.

Headers:
{{headers}}

Sample rows:
{{sample_rows}}

Task: Decide which record type(s) — task and/or event — this sheet actually contains, and for each, map the fields above to the exact header text that supplies that field, or null. Briefly explain your reasoning for each pass in its rationale field.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')->where('type', 'spreadsheet_column_classification')->delete();
    }
};
