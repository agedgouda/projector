<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templateId = DB::table('ai_templates')->insertGetId([
            'name' => 'Notes to Requirements',
            'type' => 'workflow',
            'system_prompt' => 'You are a Business Analyst specializing in requirements gathering. Your expertise lies in extracting clear, structured business requirements from meeting notes and discovery sessions.

Core Directives:

Requirement Focus: Extract business needs, not implementation details. Requirements should describe WHAT is needed, not HOW to build it.

Clarity: Each requirement should be self-contained, unambiguous, and verifiable.

Noise Reduction: Ignore conversational filler, scheduling discussion, or social small talk. Focus on business needs, constraints, and goals.

Format: Output ONLY a JSON array of objects. Do not provide any conversational preamble, explanation, or Markdown formatting (no backticks).',
            'user_prompt' => 'Source Material:
{{input}}

Transformation Task:
Analyze the meeting notes or discovery session above and extract discrete business requirements. For each identified requirement:

title: Create a short, descriptive name for the requirement.

{{output_key}}: Write a clear, concise requirement statement describing what the system or process must do or support. Focus on business value and outcomes.

criteria: Generate 2-4 acceptance criteria as an array of strings that define when this requirement is satisfied.

Strategic Instructions:

Keys: You MUST use the exact keys "title", "{{output_key}}", and "criteria".

Granularity: Break compound requirements into individual, discrete items. One requirement per business need.

Clean Extraction: If the input contains no requirements, return an empty array [].

CRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: "title", "{{output_key}}", and "criteria".',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('project_types')
            ->whereNull('organization_id')
            ->get()
            ->each(function ($type) use ($templateId) {
                $schema = json_decode($type->document_schema, true) ?? [];
                $workflow = json_decode($type->workflow, true) ?? [];

                $hasIntake = collect($schema)->contains('key', 'intake');
                $hasRequirements = collect($schema)->contains('key', 'requirements');

                if (! $hasIntake || $hasRequirements) {
                    return;
                }

                $schema[] = ['label' => 'Notes for Requirements', 'key' => 'requirements', 'is_task' => false];
                $schema[] = ['label' => 'Requirement', 'key' => 'requirement', 'is_task' => false];
                $workflow[] = ['from_key' => 'requirements', 'to_key' => 'requirement', 'ai_template_id' => $templateId];

                DB::table('project_types')
                    ->where('id', $type->id)
                    ->update([
                        'document_schema' => json_encode($schema),
                        'workflow' => json_encode($workflow),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('ai_templates')->where('name', 'Notes to Requirements')->delete();

        DB::table('project_types')
            ->whereNull('organization_id')
            ->get()
            ->each(function ($type) {
                $schema = json_decode($type->document_schema, true) ?? [];
                $workflow = json_decode($type->workflow, true) ?? [];

                $schema = collect($schema)->reject(fn ($item) => in_array($item['key'] ?? '', ['requirements', 'requirement']))->values()->all();
                $workflow = collect($workflow)->reject(fn ($step) => ($step['from_key'] ?? '') === 'requirements')->values()->all();

                DB::table('project_types')
                    ->where('id', $type->id)
                    ->update([
                        'document_schema' => json_encode($schema),
                        'workflow' => json_encode($workflow),
                        'updated_at' => now(),
                    ]);
            });
    }
};
