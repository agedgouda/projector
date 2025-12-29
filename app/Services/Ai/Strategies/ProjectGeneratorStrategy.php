<?php

namespace App\Services\Ai\Strategies;

use App\Models\Project;

interface ProjectGeneratorStrategy
{
    /**
     * Identifies which document types this strategy needs to look for.
     */
    public function getRequiredDocumentTypes(): array;

    /**
     * Builds the specific prompt to turn requirements into deliverables.
     */
    public function getTaskExtractionPrompt(): string;

    /**
     * Define the query used to find relevant chunks in the vector store.
     * e.g., "Functional requirements and system architecture"
     */
    public function getVectorSearchQuery(Project $project): string;
}
