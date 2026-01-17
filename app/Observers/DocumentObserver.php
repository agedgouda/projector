<?php

namespace App\Observers;

use App\Models\Document;
use App\Jobs\ProcessDocumentAI;
use App\Jobs\GenerateDocumentEmbedding;
use App\Events\DocumentVectorized;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document)
    {
        // 1. Trigger AI Generation for Intakes
        // We prioritize the AI Job; it can trigger its own embedding once done.
        if ($document->type === 'intake' && !$document->processed_at) {
            ProcessDocumentAI::dispatch($document);
            return; // Exit here to avoid redundant embedding jobs if AI is going to rewrite it anyway
        }

        // 2. Trigger Vectorization for other documents (like manually created ones)
        if ($document->content && is_null($document->embedding)) {
            GenerateDocumentEmbedding::dispatch($document);
        }
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        // Re-vectorize if content changes
        // Use isDirty to check if the field was changed before save
        if ($document->isDirty('content') && $document->content) {
            GenerateDocumentEmbedding::dispatch($document);
        }

        // Broadcast status changes
        if ($document->wasChanged('processed_at') && $document->processed_at !== null) {
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
