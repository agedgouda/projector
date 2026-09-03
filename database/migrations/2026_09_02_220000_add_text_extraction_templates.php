<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the two global templates TextExtractionService looks up by type — the text-source
     * counterpart to the 'spreadsheet_column_classification' template (see the
     * 2026_09_02_210500 migration), plus a second template for the actual per-pass extraction
     * call, which the spreadsheet path doesn't need (resolving a column mapping against real
     * rows doesn't require a second AI call the way extracting records from prose does).
     */
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Text Extraction Classification',
            'type' => 'text_extraction_classification',
            'system_prompt' => 'You are an expert at reading a messy, real-world text document — meeting notes, an email, a plain-text list someone kept by hand — and figuring out what structured data it actually contains. The system you support keeps two completely separate kinds of records — Events (something with a name and a date or date range) and Tasks (a discrete deliverable or action item with its own due date) — and never merges the two into one record.

Core Directives:

One Pass Per Record Type: Propose one "pass" per record type you find real evidence for in the text: usually exactly one "event" pass, plus a "task" pass only when the text genuinely describes separate deliverables or action items, not just incidental mentions. If the text only contains one kind of data, return exactly one pass. If it contains neither, return no passes.

Precise, Reusable Rules: For each pass, write an extraction_rule — a plain-English rule describing exactly what marks a record of that type in this document, and how to read its fields (name, dates, tag, description) from it. Write it precisely enough that someone (or another copy of you) following it on a similarly-structured document would find the same kind of records, not just describe this one document\'s specific items.

No Invention: Never propose a pass the text does not actually support. A single passing mention is not evidence of a genuine list.

Format: Output ONLY the structured passes described in the response schema. Do not provide conversational preamble or Markdown formatting.',
            'user_prompt' => 'Here is a source document.

Source Text:
{{source_text}}

Task: Decide which record type(s) — task and/or event — this document actually contains, and for each, write a precise extraction_rule. Briefly explain your reasoning for each pass in its rationale field.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_templates')->insert([
            'name' => 'Text Extraction',
            'type' => 'text_extraction',
            'system_prompt' => 'You are an expert at extracting structured records from a text document, following a specific rule that was already worked out for exactly this kind of document. Extract every record the rule describes — do not skip any, and do not invent any the rule and the source text don\'t both support.

Field Guidance:

name: A short, descriptive title for the record. Every record must have one — a record with no real name should not be included at all.

due_at and start_date: Real dates only, as YYYY-MM-DD, or null if the source genuinely gives no date for that record. If the source gives only one date for an item, use it for due_at and leave start_date null — the caller fills in the other end of the range itself when that happens.

tag: A single short category/label for the record if the source gives one (e.g. a section heading it falls under, an explicit category column or word), otherwise null.

description: Any other relevant detail from the source worth keeping, otherwise null. Never restate the dates here as text.

priority, task_status, assignee: Only fill these in if the source text is explicit about them (e.g. "high priority", "assigned to Jane", "in progress"). Leave null rather than guessing — these rarely apply to an Event pass at all.

Format: Output ONLY the structured records described in the response schema. Do not provide conversational preamble or Markdown formatting.',
            'user_prompt' => 'Record type to extract: {{list_type}}

Extraction rule to follow:
{{extraction_rule}}

Source Text:
{{source_text}}

Task: Apply the extraction rule above to the source text and return every {{list_type}} record it describes.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')->whereIn('type', ['text_extraction_classification', 'text_extraction'])->delete();
    }
};
