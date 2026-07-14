<?php

namespace App\Observers;

use App\Events\DocumentVectorized;
use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\ProcessDocumentAI;
use App\Models\Document;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Document $document): void
    {
        // 1. Priority: AI Transformation
        // If this document type has a workflow step defined, dispatch AI processing.
        // It will dispatch the Embedding job itself after the AI "cleans up" the text.
        // Only root documents (not AI-generated outputs of a previous step) should
        // trigger this, otherwise a workflow with chained steps (e.g. intake ->
        // action_items -> task) would cascade through every step automatically.
        $hasWorkflowStep = collect($document->project->type->workflow ?? [])
            ->contains('from_key', $document->type);

        if ($hasWorkflowStep && is_null($document->processed_at) && is_null($document->parent_id)) {
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
        // Determine if this type is a task based on the project protocol
        $schema = $document->project->type->document_schema;
        $schemaItem = collect($schema)->firstWhere('key', $document->type);

        $isTask = $schemaItem['is_task'] ?? false;

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
