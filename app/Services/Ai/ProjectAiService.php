<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    public function process(Document $document)
    {
        $document->loadMissing('project.type');
        $project = $document->project;
        $projectType = $project->type;

        // 1. Find the workflow step that triggers from THIS document's type
        // The workflow is the JSON array we saved: [{from_key: 'intake', to_key: 'user_story', ai_template_id: 1}]
        $workflow = collect($projectType->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (!$step || empty($step['ai_template_id'])) {
            Log::info("No automated AI transition defined for type: {$document->type}");
            return;
        }

        // 2. Load the AI Template defined in the workflow
        $template = AiTemplate::find($step['ai_template_id']);

        if (!$template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found for workflow.");
            return;
        }

        // 3. Create the Dynamic Strategy
        $strategy = new DynamicWorkflowStrategy(
            $template,
            $step['from_key'],
            $step['to_key']
        );

        Log::info("Executing Dynamic Workflow", [
            'project' => $project->name,
            'transition' => "{$step['from_key']} -> {$step['to_key']}",
            'template' => $template->name
        ]);

        // 4. Call the LLM (Using our enhanced logic)
        return $this->callLlm($project, $strategy, $document->content);
    }

    protected function callLlm(Project $project, DynamicWorkflowStrategy $strategy, string $context)
    {
        $driverName = config('services.llm_driver', 'gemini');
        $driver = $this->resolveDriver($driverName);

        // Prepare the System Message (The Persona)
        $systemMessage = $strategy->getTaskExtractionPrompt();

        // Prepare the User Message using the template variable replacement
        // This replaces {{input}} with our actual document content
        $userMessage = str_replace(
            ['{{input}}', '{{project}}'],
            [$context, $project->name],
            $strategy->getUserPromptTemplate()
        );

        // Standard JSON enforcement suffix
        $userMessage .= "\n\nIMPORTANT: Output ONLY a valid JSON array. No preamble.";

        $result = $driver->call($systemMessage, $userMessage);

        return [
            'project_name'  => $project->name,
            'mock_response' => $result['content'] ?? [],
            'status'        => $result['status'],
            'output_type'   => $strategy->getOutputDocumentType(),
        ];
    }

    protected function resolveDriver($name)
    {
        return match ($name) {
            'ollama' => new \App\Services\Ai\Drivers\OllamaLlmDriver(),
            'gemini' => new \App\Services\Ai\Drivers\GeminiLlmDriver(),
            default  => throw new \Exception("Unsupported LLM Driver"),
        };
    }
}
