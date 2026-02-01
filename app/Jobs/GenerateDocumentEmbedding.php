<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\VectorService;
use App\Events\DocumentProcessingUpdate;
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
        broadcast(new DocumentProcessingUpdate($this->document, 'Synthesizing Document Heuristics...'));

        try {
            // The service now handles content extraction and error cleanup internally
            $embedding = $vectorService->getEmbeddingForDocument($this->document);

            if (!$embedding) {
                throw new \Exception("Vector Service returned empty embedding.");
            }

            broadcast(new DocumentProcessingUpdate($this->document, 'Finalizing Vector Integration...'));

            $this->document->updateQuietly([
                'embedding' => $embedding,
                'processed_at' => now(),
            ]);

            DB::afterCommit(function () {
                event(new DocumentVectorized($this->document->fresh()));
            });

        } catch (\Exception $e) {
            // This still runs, but the Service has already killed the spinner
            // by setting processed_at = now() before we got here.
            $this->failed($e);
            throw $e;
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
