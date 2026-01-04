<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\VectorService;
use App\Events\DocumentProcessingUpdate; // Add this
use App\Events\DocumentVectorized;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GenerateDocumentEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $document; // DECLARE THIS

    public function __construct(Document $document)
    {
        $this->document = $document; // ASSIGN THIS
    }

    public function handle(VectorService $vectorService)
    {

        // --- Milestone 1: Analyzing ---
        broadcast(new DocumentProcessingUpdate($this->document, 'Synthesizing Document Heuristics...'));

        $embedding = $vectorService->getEmbedding($this->document->content);

        if ($embedding) {
            // --- Milestone 2: Drafting/Vectorizing ---
            broadcast(new DocumentProcessingUpdate($this->document, 'Drafting Deliverables...'));

            $this->document->updateQuietly(['embedding' => $embedding]);

            DB::afterCommit(function () {
                // This event clears the status message in our Vue logic
                event(new DocumentVectorized($this->document));
            });
        }
    }

    public function failed(\Throwable $exception)
    {
        // Milestone 3: Error Cleanup
        // Tells the user it failed so the spinner doesn't spin forever
        broadcast(new DocumentProcessingUpdate($this->document, 'Processing failed.'));

        Log::error('JOB_CRASHED', [
            'id' => $this->document->id,
            'message' => $exception->getMessage()
        ]);
    }
}
