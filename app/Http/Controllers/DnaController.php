<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DnaController extends Controller
{
    /**
     * Display the DNA dashboard.
     */
    public function index()
    {
        return Inertia::render('DNA/Dashboard', [
            'documents' => Document::where('type', 'dna')->latest()->get()
        ]);
    }

    /**
     * Store a new DNA snippet.
     */
    public function store(Request $request, VectorService $vectorService)
    {
        $validated = $request->validate([
            'content' => 'required|string|min:10',
        ]);

        // 1. Generate the 768-dimension vector using Gemini
        $embedding = $vectorService->getEmbedding($validated['content']);

        // 2. Save to Postgres
        Document::create([
            'content' => $validated['content'],
            'embedding' => $embedding,
            'type' => 'dna',
        ]);

        return back()->with('success', 'Snippet vectorized and saved!');
    }

    public function update(Request $request, Document $document, VectorService $vectorService)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        // Re-generate the embedding because the text has changed
        $embedding = $vectorService->getEmbedding($request->content);

        $document->update([
            'content' => $request->content,
            'embedding' => $embedding,
        ]);

        return back()->with('success', 'Snippet updated and re-vectorized.');
    }

public function search(Request $request, VectorService $vectorService)
{
    $queryText = $request->input('query');

    if (!$queryText) {
        return redirect()->route('dna.index');
    }

    $queryVector = $vectorService->getEmbedding($queryText);
    $vectorString = '[' . implode(',', $queryVector) . ']';

    $results = \App\Models\Document::query()
        ->select('id', 'content', 'created_at')
        // Pass the array with the string TWICE
        ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vectorString])
        ->whereRaw('1 - (embedding <=> ?::vector) > 0.45', [$vectorString])
        ->orderBy('similarity', 'DESC')
        ->limit(3)
        ->get();

    return Inertia::render('DNA/Dashboard', [
        'documents' => \App\Models\Document::latest()->get(),
        'searchResults' => $results,
        'lastQuery' => $queryText
    ]);
}

    public function destroy(Document $document): RedirectResponse
    {
        // Security check: ensure we are only deleting DNA types
        if ($document->type !== 'dna') {
            abort(403, 'Unauthorized action.');
        }

        $document->delete();

        // Redirect back to the dashboard with a success message
        return back()->with('success', 'Snippet removed successfully.');
    }
}
