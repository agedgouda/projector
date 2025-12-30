<?php

namespace App\Services\Ai\Strategies;

use App\Models\Project;

class SoftwareStrategy implements ProjectGeneratorStrategy
{
    public function getRequiredDocumentTypes(): array
    {
        // Specifically looking for the 'intake' key we just standardized
        return ['intake'];
    }

    public function getVectorSearchQuery(Project $project): string
    {
        return "User requirements, feature requests, and project goals from meeting notes for: " . $project->name;
    }

    public function getTaskExtractionPrompt(): string
    {
        return <<<PROMPT
            You are an expert Agile Product Owner.
            Your task is to analyze raw meeting notes (Intake) and extract discrete User Stories.

            Format every story exactly like this:
            - **Title**: A short name for the story.
            - **Story**: As a [user persona], I want to [action] so that [value].
            - **Acceptance Criteria**: 3-5 bullet points of what 'done' looks like.

            Output the result as a JSON array of objects with keys: 'title', 'story', 'criteria'.
            Focus only on functional needs. Ignore small talk or administrative notes from the transcript.
        PROMPT;
    }
}
