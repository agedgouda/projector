<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_templates')->insert([
            'name' => 'Notes to Events',
            'type' => 'workflow',
            'output_key' => 'event',
            'system_prompt' => 'You are an expert at reading meeting notes and discovery sessions and identifying calendar events — meetings, deadlines, milestones, and other scheduled or dated occurrences mentioned in the source text.

Core Directives:

Event Focus: Only extract items that are genuinely tied to a specific point or span of time. Ignore vague future intentions with no date attached.

Dates Are Required Together: Every event you extract that has any date information MUST have both a start date and an end date filled in — never just one. If the source gives only one date for an event, that same date is BOTH the start date and the end date. If the source gives a range (e.g. "October 20 through October 22"), the earlier date is the start date and the later date is the end date. Only leave both dates blank if the event genuinely has no date mentioned anywhere.

Noise Reduction: Ignore conversational filler, action items with no date, and social small talk.

Format: Output ONLY a JSON array of objects. Do not provide any conversational preamble, explanation, or Markdown formatting (no backticks).',
            'user_prompt' => 'Source Material:
{{input}}

Transformation Task:
Analyze the meeting notes or discovery session above and extract discrete calendar events. For each identified event, determine its title, description, start date, and end date.

title: A short, descriptive name for the event.

{{output_key}}: A brief description of the event and any relevant context from the source material. Do not mention dates here — dates belong only in start_date/due_date below.

start_date and due_date are BOTH REQUIRED whenever the event has any date mentioned — never fill in one and leave the other null, and never write date information as text anywhere else in the response:
- Single date (e.g. "on November 6, 2026"): set start_date AND due_date to that same date.
- Date range (e.g. "October 20, 2026 to October 22, 2026" or "October 20 through October 22"): set start_date to the earlier date and due_date to the later date.
- Only use null for both if the event truly has no date mentioned anywhere in the source.

criteria: Always return an empty array [] for every event. Events have no acceptance criteria — do NOT use this field to restate the start/end dates or any other date information as text; dates go exclusively in the start_date/due_date fields as actual date values, never inside criteria or anywhere else.

Worked example: an event described as running "October 20, 2026 to October 22, 2026" must produce {"start_date": "2026-10-20", "due_date": "2026-10-22", "criteria": []} — not a criteria array containing strings like "Start date: 2026-10-20". An event on "November 6, 2026" alone must produce {"start_date": "2026-11-06", "due_date": "2026-11-06", "criteria": []} — the same date in both date fields, not null in either.

Strategic Instructions:

Keys: You MUST use the exact keys "title", "start_date", "due_date", "{{output_key}}", and "criteria" for every item.

Clean Extraction: If the input contains no dated events, return an empty array [].

CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "start_date", "due_date", "{{output_key}}", and "criteria". Every event with any date information must have BOTH start_date and due_date filled in as real date values — never leave start_date null while due_date is filled, and never put date text inside criteria.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Global (organization_id null) document-type catalog entry — this is what actually
        // drives labeling/is_task/grouping everywhere now (Kanban, Document Manager, sidebar),
        // independent of any ProjectType/protocol (see DocumentTypeDefinition::catalogForOrganization()).
        DB::table('document_type_definitions')->insert([
            'organization_id' => null,
            'key' => 'event',
            'label' => 'Event',
            'is_task' => false,
            'order' => (int) (DB::table('document_type_definitions')->whereNull('organization_id')->max('order') ?? 0) + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_templates')->where('name', 'Notes to Events')->delete();
        DB::table('document_type_definitions')->whereNull('organization_id')->where('key', 'event')->delete();
    }
};
