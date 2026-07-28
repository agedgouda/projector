<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectController extends Controller
{
    /**
     * A project's top-level documents — every document without a parent, regardless of
     * type (intake notes, tasks, requirements docs, etc). Nested documents are reached by
     * drilling into a parent via the document detail screen.
     */
    public function show(Project $project): \Inertia\Response
    {
        Gate::authorize('view', $project);

        $project->loadMissing('client:id,company_name');

        $notes = $project->documents()
            ->whereNull('parent_id')
            ->latest()
            ->get(['id', 'name', 'processed_at', 'created_at']);

        return Inertia::render('Mobile/Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client?->company_name,
            ],
            'notes' => $notes->map(fn (Document $note) => [
                'id' => $note->id,
                'name' => $note->name,
                'status' => $note->processed_at ? 'processed' : 'processing',
                'created_at' => $note->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
