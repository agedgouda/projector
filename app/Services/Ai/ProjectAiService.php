<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\ProjectType;
use App\Services\VectorService;
use App\Services\Ai\Strategies\ProjectGeneratorStrategy;
use App\Services\Ai\Strategies\SoftwareStrategy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    /**
     * Map project types to their specific AI Strategies.
     */
    protected array $strategyMap = [
        'software' => SoftwareStrategy::class,
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

        // 2. Resolve the string key from the ProjectType model
        $typeKey = null;

        if ($typeModel instanceof \App\Models\ProjectType) {
            // Use the "name" field, lowercased to match our map
            $typeKey = strtolower($typeModel->name);
        } elseif (is_array($typeModel)) {
            $typeKey = strtolower($typeModel['name'] ?? '');
        } else {
            $typeKey = strtolower((string)$typeModel);
        }

        // 3. Resolve the Strategy class from the map
        $strategyClass = $this->strategyMap[$typeKey] ?? null;

        if (!$strategyClass || !class_exists($strategyClass)) {
            Log::warning("No AI Strategy found for project type: {$typeKey}. Skipping AI process.");
            return;
        }

        $strategy = new $strategyClass();

        Log::info("AI Strategy Resolved", [
            'document_id' => $document->id,
            'type_key' => $typeKey,
            'strategy' => $strategyClass,
            'expects_output' => $strategy->getOutputDocumentType() // New: visibility on output
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
   protected function callLlm(Project $project, ProjectGeneratorStrategy $strategy, string $context)
    {
        // 1. Determine which driver to use from config/services.php
        $driverName = config('services.llm_driver', 'gemini');

        /** @var \App\Contracts\LlmDriver $driver */
        $driver = match ($driverName) {
            'ollama' => new \App\Services\Ai\Drivers\OllamaLlmDriver(),
            'gemini' => new \App\Services\Ai\Drivers\GeminiLlmDriver(),
            default  => throw new \Exception("Unsupported LLM Driver: {$driverName}"),
        };

        // 2. Prepare the prompts
        $systemMessage = $strategy->getTaskExtractionPrompt();
        $userMessage = "
            Project: {$project->name}
            Context: {$context}

            INSTRUCTIONS:
            - Output a valid JSON array of objects.
            - Example: [{\"title\": \"Example\", \"story\": \"As a...\", \"criteria\": [\"one\", \"two\"]}]
            - Do not include any text before or after the JSON array.
        ";

        // 3. Call the driver (All the HTTP/Regex logic is now inside the Driver files)
        $result = $driver->call($systemMessage, $userMessage);

        // 4. Return the standard format the rest of your app expects
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
    public function generateDeliverables(Project $project, ProjectGeneratorStrategy $strategy)
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
