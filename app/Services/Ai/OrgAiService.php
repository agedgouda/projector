<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\OrgDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrgAiService
{
    public function __construct(
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
    ) {}

    /**
     * Generate an action items document per project from an OrgDocument transcript.
     *
     * @return array{status: string, groups: array}
     */
    public function extractActionItems(OrgDocument $orgDocument, Collection $activeProjects): array
    {
        $template = AiTemplate::where('type', 'org_extraction')->firstOrFail();

        $projectList = $activeProjects->map(fn ($p) => sprintf(
            '- ID: %s | Project: %s | Client: %s',
            $p->id,
            $p->name,
            $p->client->company_name ?? 'Unknown'
        ))->implode("\n");

        $userPrompt = str_replace(
            ['{{org_document_name}}', '{{project_list}}', '{{transcript}}'],
            [$orgDocument->name, $projectList, $orgDocument->content],
            $template->user_prompt
        );

        $result = $this->llmDriver->call($template->system_prompt, $userPrompt, $this->getResponseSchema());

        if (($result['status'] ?? '') === 'error') {
            Log::error('OrgAiService::extractActionItems LLM failure', ['error' => $result['message'] ?? 'unknown']);
            throw new \Exception($result['message'] ?? 'AI extraction failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                organizationId: $orgDocument->organization_id,
            );
        }

        $groups = collect($result['content']['groups'] ?? [])
            ->map(fn (array $group) => [
                'group_id' => (string) Str::uuid(),
                'project_id' => $group['project_id'] ?? null,
                'project_name' => $group['project_name'] ?? 'Unknown Project',
                'client_name' => $group['client_name'] ?? '',
                'is_new' => (bool) ($group['is_new'] ?? false),
                'document_title' => $group['document_title'] ?? '',
                'document_content' => $group['document_content'] ?? '',
            ])
            ->filter(fn ($group) => ! empty($group['document_content']))
            ->values()
            ->all();

        return [
            'status' => 'pending_review',
            'groups' => $groups,
        ];
    }

    private function getResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'groups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project_id' => [
                                'type' => ['string', 'null'],
                                'description' => 'The matching project UUID from the provided list, or null if no match.',
                            ],
                            'project_name' => ['type' => 'string'],
                            'client_name' => ['type' => 'string'],
                            'is_new' => ['type' => 'boolean'],
                            'document_title' => [
                                'type' => 'string',
                                'description' => 'Title for the action items document, e.g. "Action Items for [Meeting] — [Project]".',
                            ],
                            'document_content' => [
                                'type' => 'string',
                                'description' => 'Full HTML document: <h1>, summary <p>, and <ol> of action items.',
                            ],
                        ],
                        'required' => ['project_id', 'project_name', 'client_name', 'is_new', 'document_title', 'document_content'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['groups'],
            'additionalProperties' => false,
        ];
    }
}
