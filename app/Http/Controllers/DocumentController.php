<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
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

    public function store(StoreDocumentRequest $request, Project $project)
    {

        Gate::authorize('create', [Document::class, $project]);

        $document = $project->documents()->create($request->validated());

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

    public function update(StoreDocumentRequest $request, Project $project, Document $document)
    {
        // 1. Security check: Policy handles everything
        Gate::authorize('update', $document);

        // 2. Integrity check: Ensure the document actually belongs to this project path
        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $document->update($request->validated());

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
