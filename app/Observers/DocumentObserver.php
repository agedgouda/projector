<?php

namespace App\Observers;

use App\Models\Document;
use App\Jobs\{ProcessDocumentAI, GenerateDocumentEmbedding};
use App\Events\DocumentVectorized;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Document $document): void
    {
        // 1. Priority: AI Transformation
        // If it's an intake, the AI Job handles the logic.
        // It will dispatch the Embedding job itself after the AI "cleans up" the text.
        if ($document->type === 'intake' && is_null($document->processed_at)) {
            ProcessDocumentAI::dispatch($document);
            return;
        }

        // 2. Secondary: Standard Vectorization
        // For manually added context, jump straight to embedding.
        if (!empty($document->content) && is_null($document->embedding)) {
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
