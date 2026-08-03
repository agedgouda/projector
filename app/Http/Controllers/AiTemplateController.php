<?php

namespace App\Http\Controllers;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\Organization;
use App\Services\Ai\AiUsageLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class AiTemplateController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', AiTemplate::class);

        $user = auth()->user();

        $query = AiTemplate::orderBy('type')->orderBy('name');

        if (! $user->hasRole('super-admin')) {
            $orgId = getPermissionsTeamId();
            $query->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', $orgId);
            })
                // The universal Notes -> Action Items template runs automatically for every
                // intake document and is never a manual choice — only super-admins need to see it.
                ->where('id', '!=', config('workflow.intake_to_action_items_ai_template_id'));
        }

        $templates = $query->get()->map(function (AiTemplate $t) use ($user) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'type' => $t->type,
                'organization_id' => $t->organization_id,
                'system_prompt' => $t->system_prompt,
                'user_prompt' => $t->user_prompt,
                'single_output' => $t->single_output,
                'output_key' => $t->output_key,
                'can_edit' => $user->can('update', $t),
            ];
        });

        return inertia('AiTemplates/Index', [
            'templates' => $templates,
        ]);
    }

    public function show(AiTemplate $aiTemplate)
    {
        $user = auth()->user();

        return Inertia::render('AiTemplates/Show', [
            'aiTemplate' => [
                'id' => $aiTemplate->id,
                'name' => $aiTemplate->name,
                'description' => $aiTemplate->description,
                'generation_brief' => $aiTemplate->generation_brief,
                'system_prompt' => $aiTemplate->system_prompt,
                'user_prompt' => $aiTemplate->user_prompt,
                'single_output' => $aiTemplate->single_output,
                'output_key' => $aiTemplate->output_key,
            ],
            'canEdit' => $user->can('update', $aiTemplate),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', AiTemplate::class);

        return inertia('AiTemplates/Manage', [
            'aiTemplate' => null,
        ]);
    }

    public function edit(AiTemplate $aiTemplate)
    {
        Gate::authorize('update', $aiTemplate);

        return Inertia::render('AiTemplates/Manage', [
            'aiTemplate' => $aiTemplate,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', AiTemplate::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'generation_brief' => 'nullable|string|max:2000',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
            'single_output' => 'sometimes|boolean',
            'output_key' => 'nullable|string|max:255|regex:/^[a-z0-9_]+$/',
        ]);

        $user = auth()->user();
        $validated['organization_id'] = $user->hasRole('super-admin')
            ? null
            : getPermissionsTeamId();

        $template = AiTemplate::create($validated);

        return redirect()->route('transformation-library.show', $template)
            ->with('success', 'AI Template created.');
    }

    public function update(Request $request, AiTemplate $aiTemplate)
    {
        Gate::authorize('update', $aiTemplate);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'generation_brief' => 'nullable|string|max:2000',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
            'single_output' => 'sometimes|boolean',
            'output_key' => 'nullable|string|max:255|regex:/^[a-z0-9_]+$/',
        ]);

        $aiTemplate->update($validated);

        return redirect()->route('transformation-library.show', $aiTemplate)
            ->with('success', 'AI Template updated.');
    }

    /**
     * Draft a system_prompt/user_prompt pair from a plain-English brief of what the
     * transformation should do. Stateless — used for both the create and edit forms,
     * the caller decides whether/how to apply the result.
     */
    public function generatePrompts(Request $request, AiUsageLogger $usageLogger): JsonResponse
    {
        Gate::authorize('create', AiTemplate::class);

        $validated = $request->validate([
            'brief' => 'required|string|max:2000',
        ]);

        $user = auth()->user();
        $rawOrgId = $user->hasRole('super-admin') ? null : getPermissionsTeamId();
        $orgId = is_int($rawOrgId) || is_string($rawOrgId) ? (string) $rawOrgId : null;
        $organization = $orgId ? Organization::find($orgId) : null;
        $organization?->applyDriverConfig();

        /** @var LlmDriver $llmDriver */
        $llmDriver = app(LlmDriver::class);

        $systemPrompt = <<<'PROMPT'
            You are an expert prompt engineer for a document-transformation system. Given a
            brief description of what a transformation should do, write a system_prompt and
            user_prompt pair for it.

            The system_prompt sets the AI's persona and general instructions for the task. It
            will be rendered in a rich text editor, so format it as Markdown (paragraphs,
            numbered/bulleted lists, headings where useful) rather than one long run-on
            paragraph.

            The user_prompt is the per-run template, rendered as plain text. It must contain the
            literal placeholder {{input}} where the source document's content will be
            substituted ({{input}} is required; everything else below is optional). It may also
            use:
            - {{project}} — the project's name
            - {{document_name}} — the name of the document being transformed
            - {{today}} — today's date
            - {{client_industry}} — the client's industry, when known
            - {{client_name}} — the name of the client this project is being delivered for
            - {{vendor_name}} — the name of the organization delivering the work (i.e. "us")

            If the brief implies a client-facing or contractual document (e.g. a proposal,
            statement of work, or agreement), use {{client_name}} and {{vendor_name}} directly in
            the user_prompt wherever the document needs to name the client or the vendor, rather
            than asking the AI to guess or leave a "TBD" placeholder for information the system
            already knows.

            Write both the system_prompt and user_prompt in English only, and include an explicit
            instruction in the system_prompt that the AI must respond only in English, with no
            non-English words, phrases, or characters — even in headings or examples.
            PROMPT;

        $userPrompt = "Here is the brief:\n\n{$validated['brief']}";

        $result = $llmDriver->call($systemPrompt, $userPrompt, [
            'type' => 'object',
            'properties' => [
                'system_prompt' => ['type' => 'string', 'description' => "The transformation's system prompt (persona and instructions)."],
                'user_prompt' => ['type' => 'string', 'description' => 'The transformation\'s user prompt template, containing {{input}}.'],
            ],
            'required' => ['system_prompt', 'user_prompt'],
            'additionalProperties' => false,
        ]);

        if ($result['status'] !== 'success') {
            return response()->json(['message' => 'Generation failed. Please try again.'], 422);
        }

        $driver = $result['driver'] ?? null;
        $model = $result['model'] ?? null;
        $inputTokens = $result['input_tokens'] ?? 0;
        $outputTokens = $result['output_tokens'] ?? 0;

        $fallbackDriver = config('services.llm_driver', 'openai');

        $usageLogger->log(
            is_string($driver) ? $driver : (is_string($fallbackDriver) ? $fallbackDriver : 'openai'),
            is_string($model) ? $model : '',
            'ai_template_generate',
            is_int($inputTokens) ? $inputTokens : 0,
            is_int($outputTokens) ? $outputTokens : 0,
            null,
            $orgId
        );

        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $generatedSystemPrompt = $content['system_prompt'] ?? '';
        $generatedUserPrompt = $content['user_prompt'] ?? '';

        // system_prompt is edited in a rich text editor, so it needs HTML, not raw Markdown.
        $systemPromptHtml = is_string($generatedSystemPrompt)
            ? (new GithubFlavoredMarkdownConverter)->convert($generatedSystemPrompt)->getContent()
            : '';

        return response()->json([
            'system_prompt' => $systemPromptHtml,
            'user_prompt' => is_string($generatedUserPrompt) ? $generatedUserPrompt : '',
        ]);
    }

    public function destroy(AiTemplate $aiTemplate)
    {
        Gate::authorize('delete', $aiTemplate);

        $aiTemplate->delete();

        return back()->with('success', 'AI Template deleted.');
    }

    public function duplicate(AiTemplate $aiTemplate)
    {
        Gate::authorize('duplicate', AiTemplate::class);

        $user = auth()->user();

        $copy = $aiTemplate->replicate(['id', 'created_at', 'updated_at']);
        $copy->name = $aiTemplate->name.' (Copy)';
        $copy->organization_id = $user->hasRole('super-admin') ? null : getPermissionsTeamId();
        $copy->save();

        return redirect()->route('transformation-library.edit', $copy->id)
            ->with('success', 'AI Template copied.');
    }
}
