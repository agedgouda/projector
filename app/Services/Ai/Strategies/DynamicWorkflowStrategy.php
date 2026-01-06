<?php

namespace App\Services\Ai\Strategies;

use App\Models\AiTemplate;

class DynamicWorkflowStrategy implements ProjectGeneratorStrategy
{
    public function __construct(
        protected AiTemplate $template,
        protected string $sourceType,
        protected string $targetType
    ) {}

    public function getRequiredDocumentTypes(): array
    {
        return [$this->sourceType];
    }

    public function getOutputDocumentType(): string
    {
        return $this->targetType;
    }

    public function getTaskExtractionPrompt(): string
    {
        // System prompt from our new AiTemplate manager
        return $this->template->system_prompt;
    }

    public function getUserPromptTemplate(): string
    {
        // The prompt containing {{input}}
        return $this->template->user_prompt;
    }

    public function getVectorSearchQuery($project): string
    {
        return "Relevant information for {$this->targetType} based on {$this->sourceType} for project {$project->name}";
    }
}
