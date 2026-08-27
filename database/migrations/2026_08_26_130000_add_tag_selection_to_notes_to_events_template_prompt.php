<?php

use App\Models\AiTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adds tag selection to the "Notes to Events" template's own stored prompt — same
     * transformation-library-owns-the-rules approach as the "Create Tasks" migration
     * (2026_08_26_120000). Events are capped to a single tag app-wide (see
     * DocumentController::updateCategories()'s max:1 rule and ProcessDocumentAI's
     * array_slice(...,0,1) safety net), so this template's own prompt asks for at most one,
     * rather than relying on the app to arbitrarily truncate a longer list.
     */
    public function up(): void
    {
        $template = AiTemplate::where('name', 'Notes to Events')->first();

        if (! $template) {
            return;
        }

        $template->update([
            'system_prompt' => str_replace(
                'Noise Reduction: Ignore conversational filler, action items with no date, and social small talk.'."\n\n".'Format: Output ONLY a JSON array of objects.',
                'Noise Reduction: Ignore conversational filler, action items with no date, and social small talk.'."\n\n".'Tags: If any of this project\'s tags clearly apply to the event\'s content — {{available_tags}} — include the single most relevant one\'s exact name in "tag_names" (events can only carry one tag). Use an empty array if none clearly apply.'."\n\n".'Format: Output ONLY a JSON array of objects.',
                $template->system_prompt
            ),
            'user_prompt' => str_replace(
                [
                    'never inside criteria or anywhere else.'."\n\n".'Worked example:',
                    'Keys: You MUST use the exact keys "title", "start_date", "due_date", "{{output_key}}", and "criteria" for every item.',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "start_date", "due_date", "{{output_key}}", and "criteria". Every event with any date information must have BOTH start_date and due_date filled in as real date values — never leave start_date null while due_date is filled, and never put date text inside criteria.',
                ],
                [
                    'never inside criteria or anywhere else.'."\n\n".'tag_names: An array containing at most one exact tag name from this project\'s tag list — {{available_tags}} — that best applies to this event. Use [] if none clearly apply.'."\n\n".'Worked example:',
                    'Keys: You MUST use the exact keys "title", "start_date", "due_date", "{{output_key}}", "criteria", and "tag_names" for every item.',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "start_date", "due_date", "{{output_key}}", "criteria", and "tag_names". Every event with any date information must have BOTH start_date and due_date filled in as real date values — never leave start_date null while due_date is filled, and never put date text inside criteria.',
                ],
                $template->user_prompt
            ),
        ]);
    }

    public function down(): void
    {
        $template = AiTemplate::where('name', 'Notes to Events')->first();

        if (! $template) {
            return;
        }

        $template->update([
            'system_prompt' => str_replace(
                'Noise Reduction: Ignore conversational filler, action items with no date, and social small talk.'."\n\n".'Tags: If any of this project\'s tags clearly apply to the event\'s content — {{available_tags}} — include the single most relevant one\'s exact name in "tag_names" (events can only carry one tag). Use an empty array if none clearly apply.'."\n\n".'Format: Output ONLY a JSON array of objects.',
                'Noise Reduction: Ignore conversational filler, action items with no date, and social small talk.'."\n\n".'Format: Output ONLY a JSON array of objects.',
                $template->system_prompt
            ),
            'user_prompt' => str_replace(
                [
                    'never inside criteria or anywhere else.'."\n\n".'tag_names: An array containing at most one exact tag name from this project\'s tag list — {{available_tags}} — that best applies to this event. Use [] if none clearly apply.'."\n\n".'Worked example:',
                    'Keys: You MUST use the exact keys "title", "start_date", "due_date", "{{output_key}}", "criteria", and "tag_names" for every item.',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "start_date", "due_date", "{{output_key}}", "criteria", and "tag_names". Every event with any date information must have BOTH start_date and due_date filled in as real date values — never leave start_date null while due_date is filled, and never put date text inside criteria.',
                ],
                [
                    'never inside criteria or anywhere else.'."\n\n".'Worked example:',
                    'Keys: You MUST use the exact keys "title", "start_date", "due_date", "{{output_key}}", and "criteria" for every item.',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "start_date", "due_date", "{{output_key}}", and "criteria". Every event with any date information must have BOTH start_date and due_date filled in as real date values — never leave start_date null while due_date is filled, and never put date text inside criteria.',
                ],
                $template->user_prompt
            ),
        ]);
    }
};
