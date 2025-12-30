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
        if ($document->type === 'intake' && !$document->processed_at) {
            \App\Jobs\ProcessDocumentAI::dispatch($document);
        }
    }
    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        //
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
