<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\VectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDocumentEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Document $document) {}

    public function handle(VectorService $vectorService)
    {
        // Don't re-process if someone already filled it
        if (!empty($this->document->embedding) && $this->document->embedding !== '[]') {
            return;
        }

        $embedding = $vectorService->getEmbedding($this->document->content);

        // updateQuietly prevents the Observer from firing again
        $this->document->updateQuietly([
            'embedding' => $embedding
        ]);
    }
}
