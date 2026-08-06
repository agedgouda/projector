<?php

namespace App\Services\Ai;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use App\Models\WorkflowStep;
use App\Services\Ai\Strategies\DynamicWorkflowStrategy;
use App\Services\VectorService;
use Illuminate\Support\Facades\Log;

class ProjectAiService
{
    public function __construct(
        protected VectorService $vectorService,
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
    ) {}

    /**
     * When $overrideStep is given, runs that exact transition — a user-chosen protocol step, or a
     * raw AI template pick — instead of looking one up automatically. A protocol-driven override
     * (project_type_id set) locks the document's whole downstream lineage to that protocol; a
     * template-only override (project_type_id omitted) locks nothing, so its own children still
     * need an explicit choice.
     *
     * Without an override, this either runs the universal, protocol-independent Notes -> Action
     * Items step (the only transition that's ever automatic — see DocumentObserver::created(),
     * which relies on this same detection for reprocessing to stay consistent with creation), or,
     * for a document already locked to a protocol from an earlier transform, that protocol's own
     * workflow_steps row for this document's current type — propagating the lock to whatever gets
     * created next. A document that's neither intake, nor locked, nor given an override has
     * nothing to reprocess into.
     *
     * @param  array{to_key: string, ai_template_id: int, single_output?: bool, project_type_id?: string|null}|null  $overrideStep
     * @param  string|null  $oneOffInstructions  Free text typed at the moment of dispatch (e.g. on the
     *                                           Reprocess confirmation) — appended to whichever system
     *                                           prompt this run resolves to, for this run only. Never
     *                                           persisted anywhere; lives only in the queued job's
     *                                           payload for this one dispatch.
     */
    public function process(Document $document, ?array $overrideStep = null, ?string $oneOffInstructions = null)
    {
        $document->loadMissing('project.client');
        $project = $document->project;

        if ($overrideStep) {
            $step = $overrideStep + ['from_key' => $document->type];
            $lockedProjectTypeId = $overrideStep['project_type_id'] ?? null;
        } elseif (! empty($document->custom_prompt) && $project instanceof Project) {
            return $this->processCustomPrompt($project, $document, $oneOffInstructions);
        } elseif ($document->type === config('workflow.intake_key')) {
            $actionItemsKey = config('workflow.action_items_key');
            $templateId = config('workflow.intake_to_action_items_ai_template_id');

            if (! is_string($actionItemsKey) || ! is_int($templateId)) {
                Log::error('config(workflow.action_items_key)/config(workflow.intake_to_action_items_ai_template_id) is misconfigured.');

                return null;
            }

            $step = [
                'from_key' => $document->type,
                'to_key' => $actionItemsKey,
                'ai_template_id' => $templateId,
                'single_output' => false,
            ];
            $lockedProjectTypeId = null;
        } elseif ($document->locked_project_type_id) {
            $workflowStep = WorkflowStep::query()
                ->where('project_type_id', $document->locked_project_type_id)
                ->where('from_key', $document->type)
                ->first();

            if (! $workflowStep) {
                Log::warning("No further workflow step defined for the locked protocol on type: {$document->type}. Skipping.");

                return null;
            }

            $step = [
                'from_key' => $workflowStep->from_key,
                'to_key' => $workflowStep->to_key,
                'ai_template_id' => $workflowStep->ai_template_id,
                'single_output' => $workflowStep->single_output,
            ];
            $lockedProjectTypeId = $document->locked_project_type_id;
        } else {
            Log::warning("Document type {$document->type} is not locked to a protocol and no override step was given. Skipping.");

            return null;
        }

        if (empty($step['ai_template_id'])) {
            Log::warning("No AI transition defined for type: {$document->type}. Skipping.");

            return null;
        }

        $outputKey = $step['to_key'];

        $template = AiTemplate::find($step['ai_template_id']);
        if (! $template) {
            Log::error("AI Template ID {$step['ai_template_id']} not found.");

            return null;
        }

        $strategy = new DynamicWorkflowStrategy($template, $step['from_key'], $outputKey);
        // Whether this produces one cohesive document vs a list of items is a property of the
        // template's own prompt design, not of how it was invoked — the template's own flag is
        // authoritative regardless of whether this came from a protocol step or a direct pick.
        $singleOutput = (bool) $template->single_output;

        $result = $singleOutput
            ? $this->callLlmSingleDocument($project, $strategy, $document->content, $document, $oneOffInstructions)
            : $this->callLlm($project, $strategy, $document->content, $document, $outputKey, $oneOffInstructions);

        if (($result['status'] ?? '') === 'success') {
            $result['output_type'] = $strategy->getOutputDocumentType();
            $result['single_output'] = $singleOutput;
            $result['locked_project_type_id'] = $lockedProjectTypeId;
            $result['ai_template_id'] = $template->id;
        }

        return $result;
    }

    /**
     * A document's own stored `custom_prompt` fully replaces the default template lookup for the
     * automatic Notes -> Action Items step: same output type, same child-document creation
     * mechanism (handled by the normal single_output branch in ProcessDocumentAI — nothing
     * document-persistence-related is special-cased here), same untouched source document — only
     * the instructions driving the LLM call differ.
     *
     * Unlike every template-driven transition, this sends the instructions and the document's
     * content together as a single free-form message — the same shape as pasting a prompt and a
     * transcript into a chat UI — and asks for a plain-text completion rather than constraining
     * the model to a JSON schema. Structured-output mode measurably pushes models toward safe,
     * generic boilerplate (extra "Action Items"/"Open Questions" sections nobody asked for)
     * instead of following the prompt's own framing, which defeats the point of a custom prompt.
     * Every other transition still goes through callLlm()/callLlmSingleDocument() and the normal
     * schema-constrained call() unchanged.
     *
     * @return array{project_name: string, mock_response: array{title: string, content: string}, status: string, output_type: string|null, single_output: bool, locked_project_type_id: null}
     */
    protected function processCustomPrompt(Project $project, Document $document, ?string $oneOffInstructions = null): array
    {
        $replacements = $this->buildReplacements($project, $document);

        $instructions = str_replace(array_keys($replacements), array_values($replacements), (string) $document->custom_prompt);
        $instructions = $this->htmlToPlainText($instructions);
        $instructions .= "\n\nBegin your response with a short, descriptive title for this document on its own line, then a blank line, then the full content.";

        if (! empty($oneOffInstructions)) {
            $instructions .= "\n\nFor this run only, also follow this instruction: ".$oneOffInstructions;
        }

        $userMessage = $instructions."\n\n---\n\n".$document->content;
        $systemPrompt = ltrim($this->withEnglishOnlyConstraint(''));

        $result = $this->llmDriver->completeFreeform($systemPrompt, $userMessage);

        if (($result['status'] ?? '') === 'error') {
            Log::error('LLM Driver Failure', ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                project: $project,
            );
        }

        [$title, $content] = $this->splitTitleAndContent((string) ($result['content'] ?? ''), (string) $document->name);

        $actionItemsKey = config('workflow.action_items_key');

        return [
            'project_name' => $project->name,
            'mock_response' => ['title' => $title, 'content' => $content],
            'status' => 'success',
            'output_type' => is_string($actionItemsKey) ? $actionItemsKey : null,
            'single_output' => true,
            'locked_project_type_id' => null,
        ];
    }

    /**
     * Splits a free-text completion's leading title line from the rest of its content. Falls
     * back to the source document's own name when the response doesn't clearly lead with one
     * (e.g. no blank line within the first line or two) — this is a plain-text convention, not a
     * schema the model can fail to satisfy, so it degrades gracefully either way.
     *
     * @return array{0: string, 1: string}
     */
    private function splitTitleAndContent(string $response, string $fallbackName): array
    {
        $trimmed = ltrim($response);
        $firstBreak = strpos($trimmed, "\n");

        if ($firstBreak === false || $firstBreak > 200) {
            return [$fallbackName.' — Action Items', $trimmed];
        }

        $title = trim(ltrim(substr($trimmed, 0, $firstBreak), "# \t"));
        $rest = ltrim(substr($trimmed, $firstBreak + 1));

        if ($title === '') {
            return [$fallbackName.' — Action Items', $trimmed];
        }

        return [$title, $rest];
    }

    /**
     * The placeholder set every template's system_prompt/user_prompt can use, aside from
     * {{input}} (added separately by each caller, since only they know the source context).
     *
     * @return array<string, string>
     */
    protected function buildReplacements(Project $project, ?Document $currentDoc = null, ?string $outputKey = null): array
    {
        $client = $project->client;
        $industry = $client !== null ? $client->industry : null;
        $clientName = $client !== null ? $client->company_name : null;
        $organization = $client !== null ? $client->organization : null;
        $vendorName = $organization !== null ? $organization->name : null;

        $replacements = [
            '{{project}}' => $project->name,
            '{{document_name}}' => $currentDoc?->name ?? 'Document',
            '{{today}}' => \Illuminate\Support\Carbon::today()->toDateString(),
            '{{client_industry}}' => $industry ? "Client Industry: {$industry}" : '',
            // The client this project is being delivered for.
            '{{client_name}}' => $clientName ?? 'TBD',
            // The organization running Projector — i.e. the vendor/delivery org for this project.
            '{{vendor_name}}' => $vendorName ?? 'TBD',
        ];

        if ($outputKey !== null) {
            $replacements['{{output_key}}'] = $outputKey;
        }

        return $replacements;
    }

    protected function callLlmSingleDocument(Project $project, $strategy, string $context, ?Document $currentDoc = null, ?string $oneOffInstructions = null): array
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        $replacements = $this->buildReplacements($project, $currentDoc) + ['{{input}}' => $context];

        $userMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        if (! empty($project->description) && $project->description_quality === 'good') {
            $userMessage .= "\n\nProject Context:\n{$project->description}";
        }

        $userMessage .= "\n\nCRITICAL: Return a single JSON object (NOT an array) with exactly two keys: \"title\" (string) and \"content\" (a complete Markdown document).";

        $rawSystemPrompt = str_replace(array_keys($replacements), array_values($replacements), $strategy->getTaskExtractionPrompt());
        $systemPrompt = $this->htmlToPlainText($rawSystemPrompt);

        if (! empty($oneOffInstructions)) {
            $systemPrompt .= "\n\nFor this run only, also follow this instruction: ".$oneOffInstructions;
        }

        $systemPrompt = $this->withEnglishOnlyConstraint($systemPrompt);

        $singleDocSchema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['title', 'content'],
            'additionalProperties' => false,
        ];

        $result = $this->llmDriver->call($systemPrompt, $userMessage, $singleDocSchema);

        if (($result['status'] ?? '') === 'error') {
            Log::error('LLM Driver Failure', ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                project: $project,
            );
        }

        $doc = $result['content'] ?? [];

        return [
            'project_name' => $project->name,
            'mock_response' => $doc,
            'status' => 'success',
        ];
    }

    protected function callLlm(Project $project, $strategy, string $context, ?Document $currentDoc = null, string $outputKey = 'content', ?string $oneOffInstructions = null)
    {
        $userTemplate = $strategy->getUserPromptTemplate();
        $replacements = $this->buildReplacements($project, $currentDoc, $outputKey) + ['{{input}}' => $context];

        $baseMessage = str_replace(array_keys($replacements), array_values($replacements), $userTemplate);

        if (! empty($project->description) && $project->description_quality === 'good') {
            $baseMessage .= "\n\nProject Context:\n{$project->description}";
        }

        $mentionedUsers = $this->extractMentionedUsers($context);

        $schemaInstruction = "\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"{$outputKey}\", \"criteria\", and \"priority\" (one of \"low\", \"medium\", or \"high\"). Also include \"due_date\" (an ISO 8601 date string YYYY-MM-DD, or null if no date is mentioned).";

        if (! empty($mentionedUsers)) {
            $names = implode(', ', array_map(fn (string $name): string => '"'.$name.'"', $mentionedUsers));
            $schemaInstruction .= " Also include \"assignee_name\": if an item is clearly assigned to one of the people mentioned in the source text — {$names} — set it to their exact name as given; otherwise set it to null. Never use a name that isn't in that list.";
        }

        $userMessage = $baseMessage.$schemaInstruction;

        $rawSystemPrompt = str_replace(array_keys($replacements), array_values($replacements), $strategy->getTaskExtractionPrompt());
        $systemPrompt = $this->htmlToPlainText($rawSystemPrompt);

        if (! empty($oneOffInstructions)) {
            $systemPrompt .= "\n\nFor this run only, also follow this instruction: ".$oneOffInstructions;
        }

        $systemPrompt = $this->withEnglishOnlyConstraint($systemPrompt);

        if (! empty($mentionedUsers)) {
            // Most templates' own system prompts explicitly say not to extract assignee/owner
            // information (and constrain the response to an exact key list) — reasonable
            // defaults when nobody was mentioned, but they'd otherwise silently override the
            // assignee_name instruction just added to the user message above.
            $systemPrompt .= "\n\nException to any instruction above about not extracting assignee/owner information, or about which JSON keys are allowed: for this request only, also follow the assignee_name instructions given below.";
        }

        $result = $this->llmDriver->call(
            $systemPrompt,
            $userMessage
        );

        if (($result['status'] ?? '') === 'error') {
            Log::error('LLM Driver Failure', ['error' => $result['message'] ?? 'Unknown error']);
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                project: $project,
            );
        }

        $items = $this->normalizeOutputKeys($result['content'] ?? [], $outputKey);
        $items = $this->resolveAssigneeIds($items, $mentionedUsers);

        return [
            'project_name' => $project->name,
            'mock_response' => $items,
            'status' => 'success',
            'output_type' => $strategy->getOutputDocumentType(),
        ];
    }

    /**
     * LLMs sometimes ignore the output key name and use a generic key like "content" or
     * "description". This finds the actual content field and renames it to $expectedKey.
     */
    private function normalizeOutputKeys(array $items, string $expectedKey): array
    {
        if (empty($items) || isset($items[0][$expectedKey])) {
            return $items;
        }

        $fallbacks = ['content', 'description', 'text', 'body', 'task', 'action_item'];
        $found = null;

        foreach ($fallbacks as $candidate) {
            if (isset($items[0][$candidate])) {
                $found = $candidate;
                break;
            }
        }

        if (! $found) {
            return $items;
        }

        return array_map(function (array $item) use ($found, $expectedKey) {
            $item[$expectedKey] = $item[$found];
            unset($item[$found]);

            return $item;
        }, $items);
    }

    /**
     * Extracts every @-mentioned user from a document's HTML content — rendered by the
     * frontend's TipTap Mention extension as <span class="mention" data-id="…" data-label="…">
     * — so the prompt can ask the model to assign generated tasks to whoever was actually
     * mentioned in the source text, never someone it invents.
     *
     * @return array<int, string> user id => display name
     */
    private function extractMentionedUsers(string $html): array
    {
        if (! str_contains($html, 'data-id')) {
            return [];
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $mentions = [];

        foreach ($dom->getElementsByTagName('span') as $span) {
            if (! str_contains($span->getAttribute('class'), 'mention')) {
                continue;
            }

            $id = $span->getAttribute('data-id');
            $label = $span->getAttribute('data-label');

            if ($id !== '' && $label !== '') {
                $mentions[(int) $id] = $label;
            }
        }

        return $mentions;
    }

    /**
     * Maps each item's AI-provided "assignee_name" back to the real user id it corresponds
     * to, matching only against the mentions actually present in the source document — never
     * a fresh database lookup — so a task can only be assigned to someone who was genuinely
     *
     * @-mentioned there, and never to a name the model invented. Items with no match, or no
     * mentions to match against, are left without an assignee.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $mentionedUsers  user id => display name
     * @return array<int, array<string, mixed>>
     */
    private function resolveAssigneeIds(array $items, array $mentionedUsers): array
    {
        if (empty($mentionedUsers)) {
            return $items;
        }

        $idsByLowerName = [];
        foreach ($mentionedUsers as $id => $name) {
            $idsByLowerName[mb_strtolower(trim($name))] = $id;
        }

        return array_map(function (array $item) use ($idsByLowerName) {
            $name = $item['assignee_name'] ?? null;
            unset($item['assignee_name']);

            if (is_string($name) && isset($idsByLowerName[mb_strtolower(trim($name))])) {
                $item['assignee_id'] = $idsByLowerName[mb_strtolower(trim($name))];
            }

            return $item;
        }, $items);
    }

    /**
     * Every generated document must be in English, regardless of what a template's own
     * system_prompt says — appended last so it takes precedence over template authors who
     * didn't think to specify a language.
     */
    private function withEnglishOnlyConstraint(string $systemPrompt): string
    {
        return $systemPrompt."\n\nRespond only in English. Do not use any non-English words, phrases, or characters, including in headings, labels, or examples.";
    }

    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<(p|li|br|h[1-6])[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
