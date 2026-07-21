<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    /**
     * Show the form for creating a new document.
     */
    public function create(Request $request, Project $project)
    {
        Gate::authorize('create', [Document::class, $project]);

        return inertia('Documents/Create', [
            'project' => $project->load(['type', 'client.organization.users', 'client.organization.invitations']),
            'redirectUrl' => $request->query('redirect'),
        ]);
    }

    /**
     * Store a newly created document in storage.
     */
    public function store(StoreDocumentRequest $request, Project $project)
    {
        Gate::authorize('create', [Document::class, $project]);

        // The document only cares about validated data
        $document = $project->documents()->create(array_merge(
            $request->validated(),
            ['creator_id' => $request->user()->id]
        ));

        $definition = $project->documentTypeCatalog()->get($document->type);
        $isTask = $definition instanceof \App\Models\DocumentTypeDefinition && $definition->is_task;

        $target = $isTask
            ? ($request->query('redirect') ?? route('projects.show', $project).'?tab=tasks')
            : route('projects.show', $project).'?tab=hierarchy';

        return redirect()->to($target)
            ->with('success', 'Document created successfully.');
    }

    /**
     * Display the specified document.
     */
    public function show(Project $project, Document $document)
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        return inertia('Documents/Show', [
            'project' => $project->load(['type', 'client.organization.users', 'client.organization.invitations']),
            'item' => $document->load(['assignee', 'pendingAssignee', 'creator', 'editor', 'comments.user', 'parent.parent.parent'])
                ->loadExists('lockedNextWorkflowStep'),
        ]);
    }

    /**
     * Update the specified document in storage.
     */
    public function update(StoreDocumentRequest $request, Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        // Track who is editing the document, and when its content last changed (as opposed to
        // updateAttributes()'s quick sidebar edits) so the frontend can offer to reprocess only
        // when there's actually new content the last AI run hasn't seen yet.
        $document->update(array_merge(
            $request->validated(),
            ['editor_id' => $request->user()->id, 'content_updated_at' => now()]
        ));

        return back()->with('success', 'Document updated.');
    }

    /**
     * Update only task attributes (assignee, status, due date).
     * Allowed by any org member, unlike the full update which requires project access.
     */
    public function updateAttributes(Request $request, Project $project, Document $document)
    {
        Gate::authorize('updateAttributes', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $otherValidated = $request->validate([
            'task_status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
        ]);

        $assigneeData = $request->has('assignee_id')
            ? $this->resolveAssignee($request->input('assignee_id'), $request, $project)
            : [];

        $document->update(array_merge($assigneeData, $otherValidated, ['editor_id' => $request->user()->id]));

        return back()->with('success', 'Task updated.');
    }

    /**
     * Resolve an assignee_id input value (user ID or "inv:{id}" prefix) into DB column data.
     *
     * @return array{assignee_id: int|null, pending_assignee_invitation_id: int|null}
     */
    private function resolveAssignee(mixed $rawAssignee, Request $request, Project $project): array
    {
        if ($rawAssignee === null) {
            return ['assignee_id' => null, 'pending_assignee_invitation_id' => null];
        }

        if (is_string($rawAssignee) && str_starts_with($rawAssignee, 'inv:')) {
            $invitationId = (int) substr($rawAssignee, 4);
            $orgId = $project->client->organization_id;

            abort_unless(
                OrganizationInvitation::where('id', $invitationId)
                    ->where('organization_id', $orgId)
                    ->exists(),
                422,
                'Invalid invitation.'
            );

            return ['assignee_id' => null, 'pending_assignee_invitation_id' => $invitationId];
        }

        $request->validate(['assignee_id' => ['required', 'exists:users,id']]);

        return ['assignee_id' => (int) $rawAssignee, 'pending_assignee_invitation_id' => null];
    }

    /**
     * Search document context via Vector Service.
     */
    public function search(Request $request, Project $project, VectorService $vectorService)
    {
        Gate::authorize('view', $project);

        $queryText = $request->input('query');
        if (! $queryText) {
            return back();
        }

        $results = $vectorService->searchContext(
            $project,
            $queryText,
            $request->user()
        );

        return inertia('Projects/Show', [
            'project' => $project->loadFullPipeline(),
            'searchResults' => $results,
        ]);
    }

    /**
     * Restart AI processing for a document.
     */
    public function reprocess(Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        $orgId = $project->client?->organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        if ($org && ($block = \App\Services\MembershipGuard::check($org, 'ai_docs'))) {
            return $block;
        }

        $document->update(['processed_at' => null]);
        \App\Jobs\ProcessDocumentAI::dispatch($document);

        return response()->json(['message' => 'AI analysis restarted.']);
    }

    /**
     * Run a specific, user-chosen transition on a document, replacing any children it previously
     * produced. Two ways to call this, matching the either/or processing choice:
     *  (a) protocol-driven: pass to_key + ai_template_id + project_type_id, resolved client-side
     *      from a chosen protocol's own workflow_steps row for this document's type — locks the
     *      resulting document's whole downstream lineage to that protocol, so further processing
     *      auto-continues via that protocol with no further choice offered;
     *  (b) direct: pass only ai_template_id — to_key is derived from the AI template's name since
     *      no protocol is involved, and nothing gets locked.
     */
    public function transition(Request $request, Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'to_key' => ['sometimes', 'string', 'max:255'],
            'ai_template_id' => ['required', 'integer', 'exists:ai_templates,id'],
            'single_output' => ['sometimes', 'boolean'],
            'project_type_id' => ['sometimes', 'nullable', 'uuid', 'exists:project_types,id'],
        ]);

        $orgId = $project->client?->organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        if ($org && ($block = \App\Services\MembershipGuard::check($org, 'ai_docs'))) {
            return $block;
        }

        $aiTemplateId = $validated['ai_template_id'];
        if (! is_int($aiTemplateId)) {
            abort(422, 'Invalid ai_template_id.');
        }

        $toKey = $validated['to_key'] ?? null;
        if (! is_string($toKey) || $toKey === '') {
            $template = \App\Models\AiTemplate::findOrFail($aiTemplateId);
            $toKey = \Illuminate\Support\Str::slug($template->name, '_');
        }

        $document->update(['processed_at' => null]);
        \App\Jobs\ProcessDocumentAI::dispatch($document, [
            'to_key' => $toKey,
            'ai_template_id' => $aiTemplateId,
            'single_output' => $validated['single_output'] ?? false,
            'project_type_id' => $validated['project_type_id'] ?? null,
        ]);

        return response()->json(['message' => 'Transition started.']);
    }

    /**
     * Options for the either/or processing picker:
     *  - protocolOptions: protocols visible to this org that define their own next step from this
     *    document's current type (path a — run that protocol's own recipe);
     *  - aiTemplates: every workflow-capable AI template (path b — run any template directly).
     */
    public function transitionOptions(Project $project, Document $document)
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $orgId = $project->client?->organization_id;

        $protocolOptions = \App\Models\ProjectType::where(function ($q) use ($orgId) {
            $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
        })
            ->whereHas('workflowSteps', fn ($q) => $q->where('from_key', $document->type)->whereNotNull('ai_template_id'))
            ->with(['workflowSteps' => fn ($q) => $q->where('from_key', $document->type)->whereNotNull('ai_template_id')])
            ->orderBy('name')
            ->get()
            ->map(function ($projectType) {
                $step = $projectType->workflowSteps->first();

                return [
                    'projectTypeId' => $projectType->id,
                    'name' => $projectType->name,
                    'toKey' => $step?->to_key,
                    'aiTemplateId' => $step?->ai_template_id,
                    'singleOutput' => $step?->single_output,
                ];
            })
            ->filter(fn (array $option) => $option['toKey'] !== null && $option['aiTemplateId'] !== null)
            ->values();

        $aiTemplates = \App\Models\AiTemplate::where('type', 'workflow')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'protocolOptions' => $protocolOptions,
            'aiTemplates' => $aiTemplates,
        ]);
    }

    /**
     * Remove the specified document from storage.
     */
    public function destroy(Project $project, Document $document)
    {
        Gate::authorize('delete', $document);

        $document->delete();

        return redirect()->route('projects.show', $project)
            ->with('success', 'Document deleted successfully');
    }
}
