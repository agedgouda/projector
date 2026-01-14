<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Services\VectorService;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    public function __construct(protected VectorService $vectorService) {}

    public function process(Document $document)
    {
        $document->loadMissing('project.type');
        $project = $document->project;
        $typeModel = $project->type;

        $workflow = collect($typeModel->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (!$step || empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");
            return null; // Explicit null for the Job to handle
        }

        $template = AiTemplate::find($step['ai_template_id']);
        if (!$template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");
            return null;
        }

        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $step['to_key']);
        $result = $this->callLlm($project, $strategy, $document->content, $document);

        // --- DEFENSIVE TRACE ---
        $mockResponse = $result['mock_response'] ?? [];
        $firstItem = collect($mockResponse)->first();

        Log::info("AI Process Result Trace", [
            'doc_id' => $document->id,
            'status' => $result['status'] ?? 'unknown',
            'item_count' => is_array($mockResponse) ? count($mockResponse) : 0,
            'first_item_keys' => is_array($firstItem) ? array_keys($firstItem) : 'N/A'
        ]);

        // --- VALIDATION: Ensure we didn't get empty strings/objects back ---
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
        }

        if (isset($result['status']) && $result['status'] === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
        }

        return $result;
    }

    protected function callLlm(Project $project, $strategy, string $context, Document $currentDoc = null)
    {
        $driverName = config('services.llm_driver', 'gemini');

        $driver = match ($driverName) {
            'ollama' => new \App\Services\Ai\Drivers\OllamaLlmDriver(),
            'gemini' => new \App\Services\Ai\Drivers\GeminiLlmDriver(),
            default  => throw new \Exception("Unsupported LLM Driver: {$driverName}"),
        };

        $userTemplate = $strategy->getUserPromptTemplate();
        $replacements = ['{{input}}' => $context, '{{project}}' => $project->name];

        if (preg_match_all('/{{all:([a-zA-Z0-9_-]+)}}/i', $userTemplate, $matches)) {
            foreach ($matches[1] as $index => $docType) {
                $fullTag = $matches[0][$index];
                $query = $project->documents()->where('type', $docType);
                if ($currentDoc) { $query->where('id', '!=', $currentDoc->id); }
                $docs = $query->get();
                $replacements[$fullTag] = $docs->isNotEmpty()
                    ? $docs->pluck('content')->implode("\n\n")
                    : "No {$docType} context provided.";
            }
        }

        $userMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);
        $result = $driver->call($strategy->getTaskExtractionPrompt(), $userMessage);

        return [
            'project_name'  => $project->name,
            'mock_response' => $result['content'] ?? [],
            'status'        => $result['status'] ?? 'error',
            'error_message' => $result['message'] ?? null,
            'output_type'   => $strategy->getOutputDocumentType(),
        ];
    }
}
