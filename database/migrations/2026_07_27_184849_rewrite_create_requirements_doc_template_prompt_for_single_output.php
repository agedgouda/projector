<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "Create Requirements Doc" template (id 9) was switched from "Create a Document Set"
     * to "Create Single Document" (single_output=true), but its prompt was never updated to
     * match — it still instructed the AI to return a JSON array of items keyed by the literal
     * (never-substituted-in-single-output-mode) "{{output_key}}" placeholder. Forced into the
     * single-document {title, content} schema, the AI complied with the array instructions
     * anyway, dumping raw escaped JSON containing the literal unsubstituted placeholder text
     * into the "content" field. This rewrites the prompt to actually describe one cohesive
     * requirements document, matching how the "Software SOW" template (id 10) is written.
     */
    public function up(): void
    {
        $systemPrompt = <<<'HTML'
            <h1>Role</h1><p>You are a senior business analyst who specializes in turning raw meeting notes into clear, structured <strong>Business Requirements Documents</strong>.</p><h1>Objective</h1><p>Transform the meeting notes below into a single, cohesive, client-ready requirements document. It must be structured, unambiguous, and useful to both business stakeholders and engineering teams.</p><h1>Output Requirements</h1><ul><li><p>Write in a <strong>clear, professional</strong> tone.</p></li><li><p>Use <strong>headings and numbered/bulleted lists</strong> — never one long paragraph.</p></li><li><p>Describe <strong>what</strong> the system or process must do, not <strong>how</strong> to build it.</p></li><li><p>Ignore conversational filler, scheduling discussion, and small talk — focus only on business needs, constraints, and goals.</p></li><li><p>If the notes are missing critical details, do not invent facts — add them to the <strong>Open Questions</strong> section instead.</p></li></ul><h1>Document Structure (use these sections in this order)</h1><ol><li><p><strong>Document Control</strong> — project name, client, vendor, date (use {{today}} if provided).</p></li><li><p><strong>Overview</strong> — a short summary of the business context and why this work matters.</p></li><li><p><strong>Business Requirements</strong> — a numbered list. For each requirement, include:</p><ul><li><p>A short, descriptive title.</p></li><li><p>A clear requirement statement describing the business need or outcome.</p></li><li><p>2–4 acceptance criteria as a bulleted sub-list, defining when the requirement is satisfied.</p></li></ul></li><li><p><strong>Assumptions</strong> — anything assumed due to missing information in the notes.</p></li><li><p><strong>Open Questions</strong> — anything that needs a stakeholder's input before work can start.</p></li></ol><h1>Final Check</h1><p>Before finishing, make sure every requirement has at least one acceptance criterion, and that nothing is invented rather than stated in the source notes.</p>
            HTML;

        $userPrompt = <<<'TEXT'
            Extract structured business requirements from the meeting notes below and produce a single requirements document.

            Project: {{project}}
            Document name: {{document_name}}
            Date: {{today}}
            Client industry: {{client_industry}}
            Client: {{client_name}}
            Vendor: {{vendor_name}}

            MEETING NOTES (source):
            {{input}}

            Instructions:
            - Follow the required section order.
            - Do not invent missing facts; use Assumptions and Open Questions instead.
            - Every requirement needs at least 2 acceptance criteria.
            TEXT;

        DB::table('ai_templates')->where('id', 9)->update([
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: the previous prompt was mismatched with single_output=true, so
        // there's nothing worth restoring it to.
    }
};
