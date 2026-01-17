<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Services\VectorService;
use App\Contracts\LlmDriver;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Facades\Octane;

class ProjectAiService
{
    // 🚀 NEW: Inject the LlmDriver alongside VectorService
    public function __construct(
        protected VectorService $vectorService,
        protected LlmDriver $llmDriver
    ) {}

    public function process(Document $document)
    {
        // 1. Efficiently load project and type data
        [$document, $typeModel] = Octane::concurrently([
            fn () => $document->loadMissing('project.type'),
            fn () => $document->project->type,
        ]);

        $project = $document->project;

        // 2. RESTORED: Find the specific step in the workflow
        $workflow = collect($typeModel->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (!$step || empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");
            return null;
        }

        // 3. RESTORED: Find the template by ID from that step
        $template = AiTemplate::find($step['ai_template_id']);
        if (!$template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");
            return null;
        }

        // 4. Initialize Strategy
        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $step['to_key']);

        // 5. Call LLM using the Injected Driver
        $result = $this->callLlm($project, $strategy, $document->content, $document);

        // 6. Restore your original validation logic
        $mockResponse = $result['mock_response'] ?? [];
        if (($result['status'] ?? '') === 'success') {
            $generatedItems = collect($mockResponse);

            $hasSubstantialContent = $generatedItems->isNotEmpty() && $generatedItems->every(function ($item) {
                $text = $item['story'] ?? $item['description'] ?? $item['content'] ?? '';
                return strlen(trim($text)) > 10;
            });

            if (!$hasSubstantialContent) {
                return [
                    'status' => 'error',
                    'message' => 'AI returned empty or insufficient content.',
                    'output_type' => $strategy->getOutputDocumentType(),
                ];
            }

            $result['output_type'] = $strategy->getOutputDocumentType();
        }

        return $result;
    }

    protected function callLlm(Project $project, $strategy, string $context, Document $currentDoc = null)
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        $replacements = ['{{input}}' => $context, '{{project}}' => $project->name];

        // 🚀 Parallel Context Resolution (Stays fast with Octane)
        if (preg_match_all('/{{all:([a-zA-Z0-9_-]+)}}/i', $userTemplate, $matches)) {
            $tasks = [];
            foreach ($matches[1] as $index => $docType) {
                $fullTag = $matches[0][$index];
                $tasks[$fullTag] = function () use ($project, $docType, $currentDoc) {
                    $query = $project->documents()->where('type', $docType);
                    if ($currentDoc) { $query->where('id', '!=', $currentDoc->id); }
                    $docs = $query->get();
                    return $docs->isNotEmpty() ? $docs->pluck('content')->implode("\n\n") : "No {$docType} context.";
                };
            }
            $replacements = array_merge($replacements, Octane::concurrently($tasks));
        }

        $userMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        // 🚀 CLEANER: Use the injected driver directly
        // No more 'match' logic or manual 'new' here!
        $result = $this->llmDriver->call(
            $strategy->getTaskExtractionPrompt(),
            $userMessage
        );

        return [
            'project_name'  => $project->name,
            'mock_response' => $result['content'] ?? [],
            'status'        => $result['status'] ?? 'error',
            'error_message' => $result['message'] ?? null,
            'output_type'   => $strategy->getOutputDocumentType(),
        ];
    }
}
