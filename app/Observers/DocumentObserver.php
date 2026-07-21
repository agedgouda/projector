<?php

namespace App\Observers;

use App\Events\DocumentVectorized;
use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\ProcessDocumentAI;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Document $document): void
    {
        // 1. Priority: the universal, protocol-independent Notes -> Action Items step.
        // This is the only transition that ever fires automatically — every step after
        // it is an explicit, user-reviewed choice (see DocumentController::transition()),
        // or an automatic continuation of a protocol locked in by an earlier choice.
        // No override is passed here — ProjectAiService::process() detects the intake type
        // itself, so reprocessing (which also calls process() without an override) stays
        // consistent with what happens on creation.
        // Only root documents (not AI-generated outputs of a previous step) trigger this.
        $isIntake = $document->type === config('workflow.intake_key');

        if ($isIntake && is_null($document->processed_at) && is_null($document->parent_id)) {
            ProcessDocumentAI::dispatch($document);

            return;
        }

        // 2. Secondary: Standard Vectorization
        // For manually added context, jump straight to embedding.
        if (! empty($document->content) && is_null($document->embedding)) {
            GenerateDocumentEmbedding::dispatch($document);
        }
    }

    public function creating(Document $document): void
    {
        // Determine if this type is a task from the shared document type catalog — not the
        // project's own protocol, since a document can be produced by any protocol's recipe
        // regardless of which protocol its project uses.
        $catalog = DocumentTypeDefinition::catalogForOrganization($document->project?->organization_id);
        $definition = $catalog->get($document->type);
        $isTask = $definition instanceof DocumentTypeDefinition && $definition->is_task;

        // If it's a taskable type and status is currently empty, default to 'todo'
        if ($isTask && is_null($document->status)) {
            $document->status = 'todo';
            $document->task_status = 'todo'; // Keep both in sync for your board
        }
    }

    public function updated(Document $document): void
    {
        // If content is dirty, the previous embedding is now invalid (Garbage in, Garbage out)
        if ($document->isDirty('content')) {
            GenerateDocumentEmbedding::dispatch($document);
        }

        // Broadcast when AI processing finishes
        if ($document->wasChanged('processed_at') && $document->processed_at) {
            broadcast(new DocumentVectorized($document))->toOthers();
        }
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "force deleted" event.
     */
    public function forceDeleted(Document $document): void
    {
        //
    }
}
