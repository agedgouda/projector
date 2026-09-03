<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use Illuminate\Support\Facades\Log;

class SpreadsheetClassificationService
{
    public function __construct(
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
    ) {}

    /**
     * Given an uploaded spreadsheet's headers and a sample of its rows, asks the AI which
     * record type(s) — task and/or event — are actually present, and proposes a column mapping
     * per type. Mirrors OrgAiService::extractActionItems()'s pattern: look up the one global
     * template by type, substitute placeholders by hand, call the LLM directly — this isn't a
     * Document/Project-driven transformation, so App\Services\Ai\ProjectAiService doesn't apply.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return array{passes: array<int, array{list_type: string, mapping: array<string, string|null>, rationale: string}>}
     */
    public function classify(array $headers, array $rows, ?string $organizationId = null): array
    {
        $template = AiTemplate::where('type', 'spreadsheet_column_classification')->firstOrFail();
        if ($template->system_prompt === null || $template->user_prompt === null) {
            // Only a 'spreadsheet_import'/'text_import' saved transformation is meant to have
            // null prompts (see AiTemplateController::rules()) — this classifier template isn't
            // one, so null here means the row itself is misconfigured, not a normal state.
            throw new \Exception("The 'spreadsheet_column_classification' template is missing its prompts.");
        }

        // Capped well below the 5000-row import limit — this is a classification sample, not
        // the import itself, so a handful of rows is plenty of evidence and keeps the prompt
        // (and cost) small regardless of how large the real sheet is.
        $sampleRows = array_slice($rows, 0, 15);

        $userPrompt = str_replace(
            ['{{headers}}', '{{sample_rows}}'],
            [$this->formatHeaders($headers), $this->formatRows($headers, $sampleRows)],
            $template->user_prompt
        );

        $result = $this->llmDriver->call($template->system_prompt, $userPrompt, $this->getResponseSchema());

        if (($result['status'] ?? '') !== 'success') {
            Log::error('SpreadsheetClassificationService::classify LLM failure', ['error' => $result['message'] ?? 'unknown']);
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

        /** @var array{passes: array<int, array{list_type: string, mapping: array<string, string|null>, rationale: string}>} */
        return $result['content'] ?? ['passes' => []];
    }

    /**
     * @param  list<string>  $headers
     */
    private function formatHeaders(array $headers): string
    {
        return implode(', ', $headers);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function formatRows(array $headers, array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => collect($headers)
                ->map(fn (string $header, int $i): string => "{$header}: ".($row[$i] ?? ''))
                ->implode(' | '))
            ->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function getResponseSchema(): array
    {
        $mappingFields = ['name', 'priority', 'task_status', 'due_at', 'assignee', 'start_date', 'description', 'tag'];

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
                            'mapping' => [
                                'type' => 'object',
                                'properties' => collect($mappingFields)->mapWithKeys(fn (string $field): array => [
                                    $field => [
                                        'type' => ['string', 'null'],
                                        'description' => "The exact header text supplying {$field}, or null.",
                                    ],
                                ])->all(),
                                'required' => $mappingFields,
                                'additionalProperties' => false,
                            ],
                            'rationale' => [
                                'type' => 'string',
                                'description' => 'A brief explanation of why this pass and mapping were proposed.',
                            ],
                        ],
                        'required' => ['list_type', 'mapping', 'rationale'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['passes'],
            'additionalProperties' => false,
        ];
    }
}
