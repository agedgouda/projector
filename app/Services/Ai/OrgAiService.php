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
     * Extract action items from an OrgDocument transcript, grouped by project.
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
            $p->client->name ?? 'Unknown'
        ))->implode("\n");

        $systemPrompt = $template->system_prompt;

        $userPrompt = str_replace(
            ['{{project_list}}', '{{transcript}}'],
            [$projectList, $orgDocument->content],
            $template->user_prompt
        );

        $result = $this->llmDriver->call($systemPrompt, $userPrompt);

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
            );
        }

        $groups = collect($result['content'] ?? [])
            ->map(fn (array $group) => [
                'group_id' => (string) Str::uuid(),
                'project_id' => $group['project_id'] ?? null,
                'project_name' => $group['project_name'] ?? 'Unknown Project',
                'client_name' => $group['client_name'] ?? '',
                'is_new' => (bool) ($group['is_new'] ?? false),
                'action_items' => collect($group['action_items'] ?? [])
                    ->map(fn ($item) => [
                        'id' => (string) Str::uuid(),
                        'content' => $item['content'] ?? '',
                    ])
                    ->filter(fn ($item) => ! empty($item['content']))
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($group) => count($group['action_items']) > 0)
            ->values()
            ->all();

        return [
            'status' => 'pending_review',
            'groups' => $groups,
        ];
    }
}
