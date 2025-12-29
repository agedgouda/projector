<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Document;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DocumentController extends Controller
{
    /**
     * Store a new document and associate it with a Project.
     * Triggered by the Upload Modal in the Project Show page.
     */
    public function store(Request $request, Project $project, VectorService $vectorService)
    {
        $validated = $request->validate([
            'content' => 'required|string|min:10',
            'type' => 'required|string', // e.g., 'tech_spec' from your schema
        ]);

        // 1. Get the vector for the content
        $embedding = $vectorService->getEmbedding($validated['content']);

        // 2. Use the relationship to create the document
        // This automatically handles the project_id assignment
        $project->documents()->create([
            'content' => $validated['content'],
            'type' => $validated['type'],
            'embedding' => $embedding,
        ]);

        return back()->with('success', 'Context document added to project.');
    }

/**
 * Update the specified document in storage.
 */
public function update(Request $request, Project $project, Document $document)
{

    $validated = $request->validate([
        'name'    => ['required', 'string', 'max:255'],
        'type'    => ['required', 'string'],
        'content' => ['nullable', 'string'],
    ]);


    if ($document->project_id !== $project->id) {
        abort(403, 'Unauthorized action.');
    }

    $document->update($validated);

    if ($request->expectsJson()) {
        return response()->json([
            'message' => 'Document updated successfully',
            'document' => $document
        ]);
    }

    return back()->with('success', 'Document updated.');
}




    /**
     * Search within a specific Project's context.
     */
    public function search(Request $request, Project $project, VectorService $vectorService)
    {
        $queryText = $request->input('query');

        if (!$queryText) {
            return back();
        }

        $queryVector = $vectorService->getEmbedding($queryText);
        $vectorString = '[' . implode(',', $queryVector) . ']';

        // Scoped search: only documents related to this project
        $results = Document::query()
            ->whereHas('project', fn($q) => $q->where('id', $project->id))
            ->select('id', 'content', 'type')
            ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vectorString])
            ->whereRaw('1 - (embedding <=> ?::vector) > 0.45', [$vectorString])
            ->orderBy('similarity', 'DESC')
            ->limit(5)
            ->get();

        return Inertia::render('Projects/Show', [
            'project' => $project->load('documents'),
            'searchResults' => $results,
        ]);
    }

    /**
     * Remove a document context.
     */
    public function destroy(Project $project, Document $document)
    {
        // Safety: Ensure the document actually belongs to the project provided in the URL
        if ($document->project_id !== $project->id) {
            abort(403);
        }

        $document->delete();

        return back()->with('success', 'Document removed.');
    }
}
