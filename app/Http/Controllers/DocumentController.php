<?php

namespace App\Http\Controllers;

use App\Models\{Project, Document, User};
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{

    public function create(Project $project)
    {
        Gate::authorize('view', $project);

        // Ensure we load the type and its schema
        $project->load('type');

        return inertia('Documents/Create', [
            'project' => $project,
            // Optional: you can pass users here if the form needs to assign on create
            'users' => $project->client->users
        ]);
    }

    public function store(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string|min:10',
            'type' => 'required|string',
            'priority' => 'required|string',
            'task_status' => 'required|string',
            'due_at' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $document =$project->documents()->create($validated);

        return to_route('projects.documents.show', [$project, $document])
        ->with('success', 'Document created successfully.');
    }

    public function show(Project $project, Document $document)
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        return inertia('Documents/Show', [
            'project' => $project->load(['type','client.users']),
            'item' => $document->load(['assignee','creator','editor']),
        ]);
    }

    public function update(Request $request, Project $project, Document $document)
    {
        // 1. Security check: Policy handles everything
        Gate::authorize('update', $document);

        // 2. Integrity check: Ensure the document actually belongs to this project path
        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $document->update($request->validate([
            // Change 'required' to 'sometimes|required'
            'name'        => 'sometimes|required|string|max:255',
            'content'     => 'nullable|string',
            'type'        => 'sometimes|required|string',
            'metadata'    => 'nullable|array',
            'assignee_id' => 'nullable|exists:users,id',
            'due_at'      => 'nullable|date',
            'task_status' => 'nullable|string',
            'priority'    => 'nullable|string',
        ]));

        return back()->with('success', 'Document updated.');
    }

    public function search(Request $request, Project $project, VectorService $vectorService)
    {
        Gate::authorize('view', $project);

        $queryText = $request->input('query');
        if (!$queryText) return back();

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

    public function reprocess(Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        $document->update(['processed_at' => null]);
        \App\Jobs\ProcessDocumentAI::dispatch($document);

        return response()->json(['message' => 'AI analysis restarted.']);
    }

    public function destroy(Project $project, Document $document)
    {
        Gate::authorize('delete', $document);
        $document->delete();
        return redirect()->route('projects.show', $project->id)
            ->with('success', 'Document deleted successfully');
    }
}
