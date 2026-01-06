<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Models\ProjectType;
use App\Services\VectorService;
use App\Services\Ai\Strategies\ProjectGeneratorStrategy;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    /**
     * Map project types to their specific AI Strategies.
     */
    protected array $strategyMap = [
        'software' => \App\Services\Ai\Strategies\SoftwareStrategy::class,
    ];

    public function __construct(
        protected VectorService $vectorService
    ) {}

    /**
     * Generic entry point for background jobs.
     */
    public function process(Document $document)
    {
        // 1. Load project AND its type relationship
        $document->loadMissing('project.type');
        $project = $document->project;
        $typeModel = $project->type;

        // --- START DYNAMIC STRATEGY RESOLUTION ---
        $workflow = collect($typeModel->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (!$step || empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");
            return;
        }

        $template = AiTemplate::find($step['ai_template_id']);

        if (!$template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");
            return;
        }

        $strategy = new DynamicWorkflowStrategy(
            $template,
            $step['from_key'],
            $step['to_key']
        );
        // --- END DYNAMIC STRATEGY RESOLUTION ---

        Log::info("AI Strategy Resolved", [
            'document_id' => $document->id,
            'strategy' => get_class($strategy),
            'expects_output' => $strategy->getOutputDocumentType()
        ]);

        // 4. Call the LLM and merge the output type into the response
        $result = $this->callLlm($project, $strategy, $document->content);

        // 5. Inject the dynamic output type so the Job knows what to create
        if (isset($result['status']) && $result['status'] === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
        }

        return $result;
    }

    /**
     * Core communication
     */
protected function callLlm(Project $project, $strategy, string $context)
{
    $driverName = config('services.llm_driver', 'gemini');

    $driver = match ($driverName) {
        'ollama' => new \App\Services\Ai\Drivers\OllamaLlmDriver(),
        'gemini' => new \App\Services\Ai\Drivers\GeminiLlmDriver(),
        default  => throw new \Exception("Unsupported LLM Driver"),
    };

    $userTemplate = $strategy->getUserPromptTemplate();

    // 1. Setup the basic replacements that we KNOW work
    $replacements = [
        '{{input}}' => $context,
        '{{project}}' => $project->name,
    ];

    // 2. Only attempt regex if the template actually contains the "all:" tag
    if (str_contains($userTemplate, '{{all:')) {
        if (preg_match_all('/{{all:([a-zA-Z0-9_-]+)}}/', $userTemplate, $matches)) {
            foreach ($matches[1] as $index => $docType) {
                $fullTag = $matches[0][$index];

                // Get documents of this type
                $docs = $project->documents()->where('type', $docType)->get();

                // If we found docs, format them; otherwise, use an empty string
                // We avoid using "---" headers here to keep the prompt clean for the LLM
                $replacements[$fullTag] = $docs->isNotEmpty()
                    ? $docs->pluck('content')->implode("\n\n")
                    : "";
            }
        }
    }

    // 3. Swap placeholders
    $userMessage = str_replace(
        array_keys($replacements),
        array_values($replacements),
        $userTemplate
    );

    // 4. Call driver
    $result = $driver->call($strategy->getTaskExtractionPrompt(), $userMessage);

    // 5. Log for debugging - this will tell us if Ollama is failing to return JSON
    if ($result['status'] === 'error') {
        Log::error("LLM Driver returned an error status. Check Ollama output.", [
            'project' => $project->name,
            'template' => get_class($strategy)
        ]);
    }

    return [
        'project_name'  => $project->name,
        'mock_response' => $result['content'] ?? [],
        'status'        => $result['status'],
        'output_type'   => $strategy->getOutputDocumentType(),
    ];
}

    /**
     * Manual trigger for generating deliverables.
     */
    public function generateDeliverables(Project $project, $strategy)
    {
        $types = $strategy->getRequiredDocumentTypes();

        $contextDocs = $project->documents()
            ->whereIn('type', $types)
            ->latest()
            ->limit(10)
            ->get();

        if ($contextDocs->isEmpty()) {
            return ['error' => 'No docs found', 'mock_response' => []];
        }

        $retrievedContent = $contextDocs->map(fn($doc) => "[Type: {$doc->type}]\nContent: {$doc->content}")->implode("\n\n---\n\n");

        return $this->callLlm($project, $strategy, $retrievedContent);
    }
}
