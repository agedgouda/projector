<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class NoteController extends Controller
{
    /**
     * A root note's own index — the note itself plus every document descended from it,
     * flattened into one scannable list (with each item's nesting depth) so a user can jump
     * straight to whichever one they actually want, instead of reading through the note's
     * full content first to reach a small child item buried at the bottom of the page.
     */
    public function show(Project $project, Document $document): \Inertia\Response
    {
        Gate::authorize('view', $document);

        if ($document->project_id !== $project->id || $document->parent_id !== null) {
            abort(404);
        }

        $catalog = DocumentTypeDefinition::catalogForOrganization($project->client?->organization_id);

        $items = collect([['document' => $document, 'depth' => 0]])
            ->concat($this->descendants($document, 1));

        return Inertia::render('Mobile/Notes/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'note' => [
                'id' => $document->id,
                'name' => $document->name,
            ],
            'items' => $items->map(fn (array $item) => array_merge(
                DocumentController::present($item['document'], $catalog),
                ['depth' => $item['depth']]
            )),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{document: Document, depth: int}>
     */
    private function descendants(Document $document, int $depth): \Illuminate\Support\Collection
    {
        $children = Document::where('parent_id', $document->id)
            ->with('assignee')
            ->get(['id', 'parent_id', 'name', 'type', 'content', 'priority', 'task_status', 'due_at', 'assignee_id']);

        return $children->flatMap(fn (Document $child) => collect([['document' => $child, 'depth' => $depth]])
            ->concat($this->descendants($child, $depth + 1)));
    }
}
