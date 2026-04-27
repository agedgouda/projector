<?php

namespace App\Observers;

use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Models\OrgDocument;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class OrgDocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function created(OrgDocument $orgDocument): void
    {
        if (! empty($orgDocument->content) && is_null($orgDocument->embedding)) {
            GenerateOrgDocumentEmbedding::dispatch($orgDocument);
        }
    }

    public function updated(OrgDocument $orgDocument): void
    {
        if ($orgDocument->isDirty('content')) {
            GenerateOrgDocumentEmbedding::dispatch($orgDocument);
        }
    }
}
