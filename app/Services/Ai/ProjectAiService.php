<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Services\VectorService;
use App\Services\Ai\Strategies\ProjectGeneratorStrategy;
use Illuminate\Support\Facades\Http; // Or your preferred LLM client

class ProjectAiService
{
    public function __construct(
        protected VectorService $vectorService
    ) {}

    /**
     * This is the only method your Controller needs to call.
     */
    public function generateDeliverables(Project $project, ProjectGeneratorStrategy $strategy)
    {
        // 1. Ask Strategy what to search for, then get the Vector from Gemini
        $queryText = $strategy->getVectorSearchQuery($project);
        $queryVector = $this->vectorService->getEmbedding($queryText);

        // 2. RETRIEVAL: Find the specific documents in the Database
        // We use the Strategy to filter which types (e.g., 'tech_spec')
        $contextDocs = $project->documents()
            ->whereIn('type', $strategy->getRequiredDocumentTypes())
            ->whereNotNull('embedding')
            ->nearestNeighbors($queryVector)
            ->limit(5)
            ->get();

        // 3. PREPARATION: Combine found docs into a string for the AI
        $retrievedContent = $contextDocs->map(function ($doc) {
            return "[Document Type: {$doc->type}]\nContent: {$doc->content}";
        })->implode("\n\n---\n\n");

        // 4. GENERATION: Send everything to the LLM
        return $this->callLlm($project, $strategy, $retrievedContent);
    }

    /**
     * Internal method to handle the actual AI chat.
     */
    protected function callLlm(Project $project, ProjectGeneratorStrategy $strategy, string $context)
    {
        $systemMessage = $strategy->getTaskExtractionPrompt();

        $userMessage = "
            Project: {$project->name}
            Business Requirements: {$project->description}

            Additional Context from Documents:
            {$context}
        ";

        // This is a placeholder for your LLM call (OpenAI, Gemini, etc.)
        // We will configure the actual API call once this structure is saved.
        return [
            'system' => $systemMessage,
            'user' => $userMessage,
            'status' => 'ready_for_llm_integration'
        ];
    }
}
