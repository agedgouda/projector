<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use Illuminate\Support\Facades\Log;

class TextExtractionService
{
    public function __construct(
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
    ) {}

    /**
     * Given a text/document source, asks the AI which record type(s) — task and/or event — are
     * actually present, and proposes a plain-English extraction rule per type. Same "propose
     * N passes" shape as SpreadsheetClassificationService::classify(), except a text source has
     * no columns to map — a pass here carries an extraction_rule (see extract() below) instead
     * of a mapping.
     *
     * @return array{passes: array<int, array{list_type: string, extraction_rule: string, rationale: string}>}
     */
    public function classify(string $text, ?string $organizationId = null): array
    {
        $template = AiTemplate::where('type', 'text_extraction_classification')->firstOrFail();
        if ($template->system_prompt === null || $template->user_prompt === null) {
            // Only a 'spreadsheet_import'/'text_import' saved transformation is meant to have
            // null prompts (see AiTemplateController::rules()) — this classifier template isn't
            // one, so null here means the row itself is misconfigured, not a normal state.
            throw new \Exception("The 'text_extraction_classification' template is missing its prompts.");
        }

        $userPrompt = str_replace('{{source_text}}', $text, $template->user_prompt);

        $result = $this->llmDriver->call($template->system_prompt, $userPrompt, $this->getClassificationSchema());

        if (($result['status'] ?? '') !== 'success') {
            Log::error('TextExtractionService::classify LLM failure', ['error' => $result['message'] ?? 'unknown']);
            throw new \Exception($result['message'] ?? 'AI classification failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                organizationId: $organizationId,
            );
        }

        /** @var array{passes: array<int, array{list_type: string, extraction_rule: string, rationale: string}>} */
        return $result['content'] ?? ['passes' => []];
    }

    /**
     * Runs one confirmed pass's extraction_rule against the full source text, returning the
     * actual records it found — unlike the spreadsheet path, there's no separate "resolve a
     * mapping against existing rows" step, since the AI produces the field values directly.
     *
     * @return array{records: array<int, array{
     *     name: string, priority: string|null, task_status: string|null, due_at: string|null,
     *     assignee: string|null, start_date: string|null, description: string|null, tag: string|null
     * }>}
     */
    public function extract(string $text, string $listType, string $extractionRule, ?string $organizationId = null): array
    {
        $template = AiTemplate::where('type', 'text_extraction')->firstOrFail();
        if ($template->system_prompt === null || $template->user_prompt === null) {
            throw new \Exception("The 'text_extraction' template is missing its prompts.");
        }

        $userPrompt = str_replace(
            ['{{list_type}}', '{{extraction_rule}}', '{{source_text}}'],
            [$listType, $extractionRule, $text],
            $template->user_prompt
        );

        $result = $this->llmDriver->call($template->system_prompt, $userPrompt, $this->getExtractionSchema());

        if (($result['status'] ?? '') !== 'success') {
            Log::error('TextExtractionService::extract LLM failure', ['error' => $result['message'] ?? 'unknown']);
            throw new \Exception($result['message'] ?? 'AI extraction failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                organizationId: $organizationId,
            );
        }

        /** @var array{records: array<int, array{
         *     name: string, priority: string|null, task_status: string|null, due_at: string|null,
         *     assignee: string|null, start_date: string|null, description: string|null, tag: string|null
         * }>} */
        return $result['content'] ?? ['records' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function getClassificationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'passes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'list_type' => [
                                'type' => 'string',
                                'enum' => ['task', 'event'],
                                'description' => 'Which record type this pass creates.',
                            ],
                            'extraction_rule' => [
                                'type' => 'string',
                                'description' => 'A plain-English rule describing what marks a record of this type in the source text, and how to read its fields (name, dates, tag, etc.) from it — precise enough that following it on a similar document would find the same kind of records.',
                            ],
                            'rationale' => [
                                'type' => 'string',
                                'description' => 'A brief explanation of why this pass was proposed.',
                            ],
                        ],
                        'required' => ['list_type', 'extraction_rule', 'rationale'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['passes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getExtractionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'priority' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                            'task_status' => ['type' => ['string', 'null']],
                            'due_at' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD or null.'],
                            'assignee' => ['type' => ['string', 'null']],
                            'start_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD or null.'],
                            'description' => ['type' => ['string', 'null']],
                            'tag' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['name', 'priority', 'task_status', 'due_at', 'assignee', 'start_date', 'description', 'tag'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['records'],
            'additionalProperties' => false,
        ];
    }
}
