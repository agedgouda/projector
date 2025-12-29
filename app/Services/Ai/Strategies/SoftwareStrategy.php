<?php

namespace App\Services\Ai\Strategies;

use App\Models\Project;

class SoftwareStrategy implements ProjectGeneratorStrategy
{
    public function getRequiredDocumentTypes(): array
    {
        return collect($project->type->document_schema)->pluck('key')->toArray();
    }

    /**
     * This defines what the Vector Search is actually looking for.
     */
    public function getVectorSearchQuery(Project $project): string
    {
        return "Functional requirements, technical specifications, and system architecture for: " . $project->description;
    }


    public function getTaskExtractionPrompt(): string
    {
        return <<<PROMPT
            You are a Technical Project Manager.
            Analyze the provided Business Requirements, Tech Spec, and Functional Spec.

            Your goal is to output a flat list of technical deliverables.
            Each deliverable must be:
            1. Actionable.
            2. Focused on a specific feature or infrastructure component.
            3. Derived directly from the Functional Requirements.
        PROMPT;
    }
}
