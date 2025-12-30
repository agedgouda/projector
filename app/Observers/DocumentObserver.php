<?php

namespace App\Observers;

use App\Models\Document;
use App\Jobs\ProcessDocumentAI;

class DocumentObserver
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document)
    {
        // A. Trigger AI Generation for Intakes
        if ($document->type === 'intake' && !$document->processed_at) {
            \App\Jobs\ProcessDocumentAI::dispatch($document);
        }

        // 2) Trigger Vectorization for anything else missing an embedding
        // This catches AI stories created in the Job that don't have embeddings yet
        if ($document->content && is_null($document->embedding)) {
            \App\Jobs\GenerateDocumentEmbedding::dispatch($document);
        }
    }
    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        //revectorize
        if ($document->isDirty('content') && $document->content) {
            \App\Jobs\GenerateDocumentEmbedding::dispatch($document);
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
