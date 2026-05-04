<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
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

    public function process(Document $document)
    {
        $document->loadMissing('project.type');
        $typeModel = $document->project->type;
        $project = $document->project;

        $workflow = collect($typeModel->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (! $step || empty($step['ai_template_id'])) {
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

        $result = $this->callLlm($project, $strategy, $document->content, $document, $outputKey);

        if (($result['status'] ?? '') === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
        }

        return $result;
    }

    protected function callLlm(Project $project, $strategy, string $context, ?Document $currentDoc = null, string $outputKey = 'content')
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        // did this change?
        $replacements = [
            '{{input}}' => $context,
            '{{project}}' => $project->name,
            '{{output_key}}' => $outputKey,
            '{{document_name}}' => $currentDoc?->name ?? 'Document',
            '{{today}}' => \Illuminate\Support\Carbon::today()->toDateString(),
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
