<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
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

        $document->loadMissing('assignee');

        $catalog = DocumentTypeDefinition::catalogForOrganization($project->client?->organization_id);

        return Inertia::render('Mobile/Documents/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            // Browsing to a sibling/child document happens via that note's own index page
            // (Mobile\NoteController) — "back" returns there rather than to the project page,
            // so the user lands back on the list they picked this item from.
            'noteId' => $this->rootAncestorId($document),
            'document' => array_merge(
                self::present($document, $catalog),
                ['status' => $document->processed_at ? 'processed' : 'processing']
            ),
        ]);
    }

    private function rootAncestorId(Document $document): string
    {
        while ($document->parent_id !== null) {
            $document = Document::query()->select(['id', 'parent_id'])->findOrFail($document->parent_id);
        }

        return $document->id;
    }

    /**
     * The same set of fields the desktop document detail sheet shows, reshaped for the
     * mobile page — used for a document itself (here) and for every item in its note's index
     * (Mobile\NoteController) so items are presented consistently rather than as a flat,
     * generically-labeled list.
     *
     * @param  \Illuminate\Support\Collection<string, DocumentTypeDefinition>  $catalog
     * @return array<string, mixed>
     */
    public static function present(Document $document, \Illuminate\Support\Collection $catalog): array
    {
        $definition = $catalog->get($document->type);

        return [
            'id' => $document->id,
            'name' => $document->name,
            'content' => $document->content,
            'typeLabel' => $definition instanceof DocumentTypeDefinition ? $definition->label : $document->type,
            'isTask' => $definition instanceof DocumentTypeDefinition && $definition->is_task,
            'priority' => $document->priority,
            'taskStatus' => $document->task_status,
            // Not cast to Carbon on the Document model (unlike Task) — passed through as
            // whatever raw string the database gives back, same as the desktop sheet does.
            'dueAt' => $document->due_at,
            'assignee' => $document->assignee ? [
                'id' => $document->assignee->id,
                'name' => $document->assignee->name,
            ] : null,
        ];
    }
}
