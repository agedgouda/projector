<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Document;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentController extends Controller
{
    /**
     * Store a new document.
     */
    public function store(Request $request, Project $project, VectorService $vectorService)
    {
        // 1. Security Check: Can the user see this project?
        if (!Project::visibleTo($request->user())->where('id', $project->id)->exists()) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string|min:10',
            'type' => 'required|string',
        ]);

        // 2. Create via Model directly to ensure Observer gets full data
        // This avoids relationship-level scoping issues during the 'created' event
        $document = Document::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'content' => $validated['content'],
            'type' => $validated['type'],
        ]);

        $document->load(['creator', 'editor']);
        return back()->with('success', 'Context document added to project.');
    }

    /**
     * Update the specified document.
     */
    public function update(Request $request, Project $project, Document $document)
    {
        // Security: Ensure document belongs to the project AND is visible to user
        if ($document->project_id !== $project->id ||
            !Document::visibleTo($request->user())->where('id', $document->id)->exists()) {
            abort(404);
        }

        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'type'    => ['required', 'string'],
            'content' => ['nullable', 'string'],
        ]);

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
        // Security: Check project access
        if (!Project::visibleTo($request->user())->where('id', $project->id)->exists()) {
            abort(404);
        }

        $queryText = $request->input('query');
        if (!$queryText) return back();

        $queryVector = $vectorService->getEmbedding($queryText);
        $vectorString = '[' . implode(',', $queryVector) . ']';

        // Apply visibleTo scope to the search query
        $results = Document::query()
            ->visibleTo($request->user())
            ->where('project_id', $project->id)
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
    public function destroy(Request $request, Project $project, Document $document)
    {
        if ($document->project_id !== $project->id ||
            !Document::visibleTo($request->user())->where('id', $document->id)->exists()) {
            abort(404);
        }

        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    /**
     * Restart AI analysis.
     */
    public function reprocess(Request $request, Project $project, Document $document)
    {
        if ($document->project_id !== $project->id ||
            !Document::visibleTo($request->user())->where('id', $document->id)->exists()) {
            abort(404);
        }

        $document->update(['processed_at' => null]);

        // Dispatching the Job (Ensure Job has public $document and __construct)
        \App\Jobs\ProcessDocumentAI::dispatch($document);

        return response()->json([
            'status' => 'success',
            'message' => 'AI analysis has been restarted.'
        ]);
    }
}
