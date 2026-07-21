<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use App\Models\WorkflowStep;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use App\Services\VectorService;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    public function __construct(
        protected VectorService $vectorService,
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
    ) {}

    /**
     * When $overrideStep is given, runs that exact transition — a user-chosen protocol step, or a
     * raw AI template pick — instead of looking one up automatically. A protocol-driven override
     * (project_type_id set) locks the document's whole downstream lineage to that protocol; a
     * template-only override (project_type_id omitted) locks nothing, so its own children still
     * need an explicit choice.
     *
     * Without an override, this either runs the universal, protocol-independent Notes -> Action
     * Items step (the only transition that's ever automatic — see DocumentObserver::created(),
     * which relies on this same detection for reprocessing to stay consistent with creation), or,
     * for a document already locked to a protocol from an earlier transform, that protocol's own
     * workflow_steps row for this document's current type — propagating the lock to whatever gets
     * created next. A document that's neither intake, nor locked, nor given an override has
     * nothing to reprocess into.
     *
     * @param  array{to_key: string, ai_template_id: int, single_output?: bool, project_type_id?: string|null}|null  $overrideStep
     */
    public function process(Document $document, ?array $overrideStep = null)
    {
        $document->loadMissing('project.client');
        $project = $document->project;

        if ($overrideStep) {
            $step = $overrideStep + ['from_key' => $document->type];
            $lockedProjectTypeId = $overrideStep['project_type_id'] ?? null;
        } elseif ($document->type === config('workflow.intake_key')) {
            $actionItemsKey = config('workflow.action_items_key');
            $templateId = config('workflow.intake_to_action_items_ai_template_id');

            if (! is_string($actionItemsKey) || ! is_int($templateId)) {
                Log::error('config(workflow.action_items_key)/config(workflow.intake_to_action_items_ai_template_id) is misconfigured.');

                return null;
            }

            $step = [
                'from_key' => $document->type,
                'to_key' => $actionItemsKey,
                'ai_template_id' => $templateId,
                'single_output' => false,
            ];
            $lockedProjectTypeId = null;
        } elseif ($document->locked_project_type_id) {
            $workflowStep = WorkflowStep::query()
                ->where('project_type_id', $document->locked_project_type_id)
                ->where('from_key', $document->type)
                ->first();

            if (! $workflowStep) {
                Log::warning("No further workflow step defined for the locked protocol on type: {$document->type}. Skipping.");

                return null;
            }

            $step = [
                'from_key' => $workflowStep->from_key,
                'to_key' => $workflowStep->to_key,
                'ai_template_id' => $workflowStep->ai_template_id,
                'single_output' => $workflowStep->single_output,
            ];
            $lockedProjectTypeId = $document->locked_project_type_id;
        } else {
            Log::warning("Document type {$document->type} is not locked to a protocol and no override step was given. Skipping.");

            return null;
        }

        if (empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");

            return null;
        }

        $outputKey = $step['to_key'];

        $template = AiTemplate::find($step['ai_template_id']);
        if (! $template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");

            return null;
        }

        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $outputKey);
        $singleOutput = (bool) ($step['single_output'] ?? false);

        $result = $singleOutput
            ? $this->callLlmSingleDocument($project, $strategy, $document->content, $document)
            : $this->callLlm($project, $strategy, $document->content, $document, $outputKey);

        if (($result['status'] ?? '') === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
            $result['single_output'] = $singleOutput;
            $result['locked_project_type_id'] = $lockedProjectTypeId;
        }

        return $result;
    }

    protected function callLlmSingleDocument(Project $project, $strategy, string $context, ?Document $currentDoc = null): array
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        $industry = $project->client?->industry;
        $replacements = [
            '{{input}}' => $context,
            '{{project}}' => $project->name,
            '{{document_name}}' => $currentDoc?->name ?? 'Document',
            '{{today}}' => \Illuminate\Support\Carbon::today()->toDateString(),
            '{{client_industry}}' => $industry ? "Client Industry: {$industry}" : '',
        ];

        $userMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        if (! empty($project->description) && $project->description_quality === 'good') {
            $userMessage .= "\n\nProject Context:\n{$project->description}";
        }

        $userMessage .= "\n\nCRITICAL: Return a single JSON object (NOT an array) with exactly two keys: \"title\" (string) and \"content\" (a complete Markdown document).";

        $rawSystemPrompt = str_replace(array_keys($replacements), array_values($replacements), $strategy->getTaskExtractionPrompt());
        $systemPrompt = $this->htmlToPlainText($rawSystemPrompt);

        $singleDocSchema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['title', 'content'],
            'additionalProperties' => false,
        ];

        $result = $this->llmDriver->call($systemPrompt, $userMessage, $singleDocSchema);

        if (($result['status'] ?? '') === 'error') {
            Log::error('LLM Driver Failure', ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                project: $project,
            );
        }

        $doc = $result['content'] ?? [];

        return [
            'project_name' => $project->name,
            'mock_response' => $doc,
            'status' => 'success',
        ];
    }

    protected function callLlm(Project $project, $strategy, string $context, ?Document $currentDoc = null, string $outputKey = 'content')
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        // did this change?
        $industry = $project->client?->industry;
        $replacements = [
            '{{input}}' => $context,
            '{{project}}' => $project->name,
            '{{output_key}}' => $outputKey,
            '{{document_name}}' => $currentDoc?->name ?? 'Document',
            '{{today}}' => \Illuminate\Support\Carbon::today()->toDateString(),
            '{{client_industry}}' => $industry ? "Client Industry: {$industry}" : '',
        ];

        $baseMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        if (! empty($project->description) && $project->description_quality === 'good') {
            $baseMessage .= "\n\nProject Context:\n{$project->description}";
        }

        $schemaInstruction = "\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"{$outputKey}\", and \"criteria\". Also include \"due_date\" (an ISO 8601 date string YYYY-MM-DD, or null if no date is mentioned).";

        $userMessage = $baseMessage.$schemaInstruction;

        $rawSystemPrompt = str_replace(array_keys($replacements), array_values($replacements), $strategy->getTaskExtractionPrompt());
        $systemPrompt = $this->htmlToPlainText($rawSystemPrompt);

        $result = $this->llmDriver->call(
            $systemPrompt,
            $userMessage
        );

        if (($result['status'] ?? '') === 'error') {
            Log::error('LLM Driver Failure', ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                project: $project,
            );
        }

        $items = $this->normalizeOutputKeys($result['content'] ?? [], $outputKey);

        return [
            'project_name' => $project->name,
            'mock_response' => $items,
            'status' => 'success',
            'output_type' => $strategy->getOutputDocumentType(),
        ];
    }

    /**
     * LLMs sometimes ignore the output key name and use a generic key like "content" or
     * "description". This finds the actual content field and renames it to $expectedKey.
     */
    private function normalizeOutputKeys(array $items, string $expectedKey): array
    {
        if (empty($items) || isset($items[0][$expectedKey])) {
            return $items;
        }

        $fallbacks = ['content', 'description', 'text', 'body', 'task', 'action_item'];
        $found = null;

        foreach ($fallbacks as $candidate) {
            if (isset($items[0][$candidate])) {
                $found = $candidate;
                break;
            }
        }

        if (! $found) {
            return $items;
        }

        return array_map(function (array $item) use ($found, $expectedKey) {
            $item[$expectedKey] = $item[$found];
            unset($item[$found]);

            return $item;
        }, $items);
    }

    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<(p|li|br|h[1-6])[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
