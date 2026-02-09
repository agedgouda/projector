<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Document;
use App\Models\AiTemplate;
use App\Services\VectorService;
use App\Contracts\LlmDriver;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    public function __construct(
        protected VectorService $vectorService,
        protected LlmDriver $llmDriver
    ) {}

    public function process(Document $document)
    {
        $document->loadMissing('project.type');
        $typeModel = $document->project->type;
        $project = $document->project;

        $workflow = collect($typeModel->workflow ?? []);
        $step = $workflow->firstWhere('from_key', $document->type);

        if (!$step || empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");
            return null;
        }

        $outputKey = $step['to_key'];

        $template = AiTemplate::find($step['ai_template_id']);
        if (!$template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");
            return null;
        }

        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $outputKey);

        $result = $this->callLlm($project, $strategy, $document->content, $document, $outputKey);

        if (($result['status'] ?? '') === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
        }

        return $result;
    }

    protected function callLlm(Project $project, $strategy, string $context, Document $currentDoc = null, string $outputKey = 'content')
    {
        $userTemplate = $strategy->getUserPromptTemplate();

        $replacements = [
            '{{input}}' => $context,
            '{{project}}' => $project->name,
            '{{output_key}}' => $outputKey,
            '{{document_name}}' => $currentDoc?->name ?? 'Document',
        ];

        $baseMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        $schemaInstruction = "\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"{$outputKey}\", and \"criteria\".";

        $userMessage = $baseMessage . $schemaInstruction;

        $result = $this->llmDriver->call(
            $strategy->getTaskExtractionPrompt(),
            $userMessage
        );

        if (($result['status'] ?? '') === 'error') {
            Log::error("LLM Driver Failure", ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        return [
            'project_name'  => $project->name,
            'mock_response' => $result['content'] ?? [],
            'status'        => 'success',
            'output_type'   => $strategy->getOutputDocumentType(),
        ];
    }
}
