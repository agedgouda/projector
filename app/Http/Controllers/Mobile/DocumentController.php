<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function show(Project $project, Document $document): \Inertia\Response
    {
        Gate::authorize('view', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $children = Document::where('parent_id', $document->id)->get(['id', 'name', 'type', 'content']);

        return Inertia::render('Mobile/Documents/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'document' => [
                'id' => $document->id,
                'name' => $document->name,
                'content' => $document->content,
                'status' => $document->processed_at ? 'processed' : 'processing',
            ],
            'children' => $children->map(fn (Document $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'type' => $child->type,
                'content' => $child->content,
            ]),
        ]);
    }
}
