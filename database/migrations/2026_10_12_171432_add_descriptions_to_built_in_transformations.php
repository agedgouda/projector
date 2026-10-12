<?php

use App\Models\AiTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-sentence descriptions for the built-in transformations, shown on the Transformation
     * Library's cards. Only fills ones that are still blank — anything already written (or edited
     * since) is left alone.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'Create Requirements Doc' => 'Turns meeting notes into a single, client-ready business requirements document.',
        'Create Tasks' => 'Pulls clear, actionable tasks out of meeting notes, transcripts, requirements, or any other written material.',
        'Create User Stories' => 'Turns project intake notes into a list of granular Agile user stories.',
        'Notes to Events' => 'Finds the meetings, deadlines, and milestones in meeting notes and turns them into calendar events.',
        'Story to Functional Requirements' => 'Expands a user story into a detailed functional requirement, with logic, validation rules, and edge cases.',
        'Technical Task Creation' => "Breaks a user story into implementation-ready developer tasks, using the project's technical specs.",
        'Transcript to Meeting Notes' => 'Turns a meeting transcript into a single Meeting Notes document that captures every action item.',
        'Status Meeting: Action Item Extraction' => 'Splits a status meeting that covered several projects into an Action Items document for each one.',
        'Spreadsheet Column Classification' => 'Used by Smart Import to work out whether a spreadsheet holds tasks, events, or both, and which columns hold which fields.',
        'Text Extraction Classification' => 'Used by Smart Import to work out whether a text file holds tasks or events, or should be filed as a document.',
        'Text Extraction' => 'Used by Smart Import to pull task or event records out of a text file, following the rule worked out for it.',
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $name => $description) {
            AiTemplate::query()
                ->whereNull('organization_id')
                ->where('name', $name)
                ->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $name => $description) {
            AiTemplate::query()
                ->whereNull('organization_id')
                ->where('name', $name)
                ->where('description', $description)
                ->update(['description' => null]);
        }
    }
};
