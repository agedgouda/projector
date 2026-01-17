<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Services\VectorService;
use App\Services\Ai\Contracts\LlmDriver; // The Interface
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
        // ... (Your existing loadMissing logic remains identical)
        [$document, $typeModel] = Octane::concurrently([
            fn () => $document->loadMissing('project.type'),
            fn () => $document->project->type,
        ]);

        // ... (Your existing workflow/template logic remains identical)

        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $step['to_key']);
        $result = $this->callLlm($project, $strategy, $document->content, $document);

        // ... (Your existing Defensive Trace and Validation remain identical)
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
