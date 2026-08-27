<?php

use App\Models\AiTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adds tag selection to the "Create Tasks" template's own stored prompt. Per this app's
     * transformation-library design, what a transformation asks the AI to do lives entirely in
     * the template's own prompt text (editable in the Transformation Library), not hardcoded
     * into application code — see how "Priority"/"Due Date" are fully specified in this same
     * prompt. The application only supplies the {{available_tags}} placeholder (the project's
     * real tag list — see ProjectAiService::buildReplacements()) and the plumbing to act on
     * whatever the template's prompt requests (the $includeTags detection in the LLM drivers,
     * and ProjectAiService::resolveTagIds()) — it never decides on its own whether or how to ask
     * for tags.
     */
    public function up(): void
    {
        $template = AiTemplate::where('name', 'Create Tasks')->first();

        if (! $template) {
            return;
        }

        $template->update([
            'system_prompt' => str_replace(
                '<li><p>Do not extract assignee or owner information.</p></li>',
                '<li><p>Tags: If any of this project\'s tags clearly apply to the task\'s content — {{available_tags}} — include their exact names in "tag_names". Use an empty array if none clearly apply.</p></li><li><p>Do not extract assignee or owner information.</p></li>',
                $template->system_prompt
            ),
            'user_prompt' => str_replace(
                [
                    "due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.\n\nStrategic Instructions:",
                    'Keys: You MUST use the exact keys "title", "{{output_key}}", "criteria", "priority", and "due_date".',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", "criteria", "priority", and "due_date".',
                ],
                [
                    "due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.\n\ntag_names: An array of zero or more exact tag names from this project's tag list — {{available_tags}} — that clearly apply to this task. Use [] if none apply.\n\nStrategic Instructions:",
                    'Keys: You MUST use the exact keys "title", "{{output_key}}", "criteria", "priority", "due_date", and "tag_names".',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", "criteria", "priority", "due_date", and "tag_names".',
                ],
                $template->user_prompt
            ),
        ]);
    }

    public function down(): void
    {
        $template = AiTemplate::where('name', 'Create Tasks')->first();

        if (! $template) {
            return;
        }

        $template->update([
            'system_prompt' => str_replace(
                '<li><p>Tags: If any of this project\'s tags clearly apply to the task\'s content — {{available_tags}} — include their exact names in "tag_names". Use an empty array if none clearly apply.</p></li><li><p>Do not extract assignee or owner information.</p></li>',
                '<li><p>Do not extract assignee or owner information.</p></li>',
                $template->system_prompt
            ),
            'user_prompt' => str_replace(
                [
                    "due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.\n\ntag_names: An array of zero or more exact tag names from this project's tag list — {{available_tags}} — that clearly apply to this task. Use [] if none apply.\n\nStrategic Instructions:",
                    'Keys: You MUST use the exact keys "title", "{{output_key}}", "criteria", "priority", "due_date", and "tag_names".',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", "criteria", "priority", "due_date", and "tag_names".',
                ],
                [
                    "due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.\n\nStrategic Instructions:",
                    'Keys: You MUST use the exact keys "title", "{{output_key}}", "criteria", "priority", and "due_date".',
                    'CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", "criteria", "priority", and "due_date".',
                ],
                $template->user_prompt
            ),
        ]);
    }
};
