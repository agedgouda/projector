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
            'project' => $project->load(['type', 'client.users']),
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

        $target = $request->query('redirect')
                ?? redirect()->intended()->getTargetUrl()
                ?? route('dashboard', ['project' => $project->id]);

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
            'item' => $document->load(['assignee', 'pendingAssignee', 'creator', 'editor', 'comments.user']),
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

        // Track who is editing the document
        $document->update(array_merge(
            $request->validated(),
            ['editor_id' => $request->user()->id]
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

        $rawAssignee = $request->input('assignee_id');
        $assigneeData = $this->resolveAssignee($rawAssignee, $request, $project);

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

        $document->update(['processed_at' => null]);
        \App\Jobs\ProcessDocumentAI::dispatch($document);

        return response()->json(['message' => 'AI analysis restarted.']);
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
