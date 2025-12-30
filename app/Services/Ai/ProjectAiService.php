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

        if ($typeModel instanceof ProjectType) {
            // Use the "name" field as requested, lowercased to match our map
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

        Log::info("AI Strategy Resolved", [
            'document_id' => $document->id,
            'type_key' => $typeKey,
            'strategy' => $strategyClass
        ]);

        $strategy = new $strategyClass();

        // 4. Pass the document content to the LLM logic
        return $this->callLlm($project, $strategy, $document->content);
    }

    /**
     * Core communication with Gemini 2.5 Flash.
     */
    protected function callLlm(Project $project, ProjectGeneratorStrategy $strategy, string $context)
    {
        $apiKey = config('services.gemini.key');
        $systemMessage = $strategy->getTaskExtractionPrompt();

        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $userMessage = "
            {$systemMessage}

            Project: {$project->name}
            Context: {$context}

            INSTRUCTIONS:
            - Output a valid JSON array of objects.
            - Each object MUST have: 'title', 'story', and 'criteria' (array of strings).
            - Do not include markdown tags like ```json.
            - Start your response with [ and end with ].
        ";

        try {
            $response = Http::post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $userMessage]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                Log::error("Gemini API Error Body: " . $response->body());
                throw new \Exception("Gemini API Error: " . $response->status());
            }

            $data = $response->json();
            $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';

            // Clean up any markdown formatting
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $textResponse));

            Log::info("AI Generation Results:", ['response' => $cleanJson]);

            return [
                'project_name' => $project->name,
                'mock_response' => json_decode($cleanJson, true) ?? [],
                'status' => 'success'
            ];

        } catch (\Exception $e) {
            Log::error("AI Generation Error: " . $e->getMessage());
            return [
                'project_name' => $project->name,
                'error' => 'AI Service Error: ' . $e->getMessage(),
                'mock_response' => []
            ];
        }
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
