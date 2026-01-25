<?php

namespace App\Http\Controllers;

use App\Models\{Project, Document};
use App\Http\Requests\StoreDocumentRequest;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    /**
     * Show the form for creating a new document.
     */
    public function create(Project $project)
    {
        // Use the same 'create' policy check as the store method
        Gate::authorize('create', [Document::class, $project]);

        return inertia('Documents/Create', [
            'project' => $project->load(['type', 'client.users']),
        ]);
    }

    /**
     * Store a newly created document in storage.
     */
    public function store(StoreDocumentRequest $request, Project $project)
    {
        Gate::authorize('create', [Document::class, $project]);

        // Merge the creator_id if your table tracks who made the doc
        $document = $project->documents()->create(array_merge(
            $request->validated(),
            ['creator_id' => $request->user()->id]
        ));

        return to_route('projects.show', $project->id)
            ->with('success', 'Document created and queued for analysis.');
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
            'project' => $project->load(['type', 'client.users']),
            'item' => $document->load(['assignee', 'creator', 'editor']),
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
     * Search document context via Vector Service.
     */
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

        return redirect()->route('projects.show', $project->id)
            ->with('success', 'Document deleted successfully');
    }
}
