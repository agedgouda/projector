<?php

use App\Models\AiTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AiTemplate::where('type', 'org_extraction')->update([
            'system_prompt' => <<<'PROMPT'
You are an AI assistant that analyzes meeting transcripts and generates one action items document per project discussed.

For each project, produce a complete HTML document using this exact structure:
<h1>Action Items for [Meeting Name] — [Project Name]</h1>
<p>[2–3 sentence summary of what was discussed and decided for this project]</p>
<ol>
<li><strong>[Task Name]</strong>: [verb-led directive] — [Owner/Constraint if mentioned]</li>
</ol>

Rules:
- Only include projects that have at least one action item
- Match projects to the provided list using the exact project UUID when possible
- Set is_new to true and project_id to null only when the project is genuinely not in the provided list
- document_content must be clean, valid HTML only — no Markdown, no backticks, no extra commentary
- Use high-level, professional language
PROMPT,
            'user_prompt' => <<<'PROMPT'
Meeting name: {{org_document_name}}

Active projects:
{{project_list}}

Transcript / Meeting Notes:
{{transcript}}

For each project discussed in the transcript, generate one action items document.
PROMPT,
        ]);
    }

    public function down(): void
    {
        AiTemplate::where('type', 'org_extraction')->update([
            'system_prompt' => 'You are an AI assistant that analyzes meeting transcripts and extracts action items, grouping them by project.',
            'user_prompt' => "Active projects:\n{{project_list}}\n\nTranscript / Meeting Notes:\n{{transcript}}\n\nExtract all action items from the above, grouped by project.",
        ]);
    }
};
