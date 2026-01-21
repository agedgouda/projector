<?php

namespace App\Http\Controllers;

use App\Models\{Project, Document};
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function store(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string|min:10',
            'type' => 'required|string',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        // Laravel 11/12: Relationship creation is cleaner
        $project->documents()->create($validated);

        return back()->with('success', 'Context document added.');
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
            'name'    => 'required|string|max:255',
            'content' => 'nullable|string',
            'type'    => 'required|string',
            'metadata'=> 'nullable|array',
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
        return back();
    }
}
