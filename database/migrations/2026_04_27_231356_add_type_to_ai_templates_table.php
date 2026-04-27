<?php

use App\Models\AiTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->string('type')->default('workflow')->after('name');
        });

        // Seed the default org extraction template
        AiTemplate::create([
            'type' => 'org_extraction',
            'name' => 'Org Document: Action Item Extraction',
            'system_prompt' => <<<'PROMPT'
You are an AI assistant that analyzes meeting transcripts and extracts action items, grouping them by project.

Return a JSON array with this exact structure. No explanation, only JSON:
[
  {
    "project_id": "the exact UUID from the project list, or null if the project is not in the list",
    "project_name": "name of the project",
    "client_name": "name of the client",
    "is_new": false,
    "action_items": [
      {"id": "ai-1", "content": "clear, actionable description"}
    ]
  }
]

Rules:
- Only include projects that have at least one action item
- Use the exact project_id UUID from the provided list when the project matches
- Set is_new to true and project_id to null only when the project is genuinely not in the list
- Generate sequential IDs for action items: "ai-1", "ai-2", etc. (unique across all groups)
- Each action item must be a complete, actionable sentence
PROMPT,
            'user_prompt' => <<<'PROMPT'
Active projects:
{{project_list}}

Transcript / Meeting Notes:
{{transcript}}

Extract all action items from the above, grouped by project.
PROMPT,
        ]);
    }

    public function down(): void
    {
        AiTemplate::where('type', 'org_extraction')->delete();

        Schema::table('ai_templates', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
