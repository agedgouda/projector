<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\VectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDocumentEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Document $document)
    {
        // LOG 0: This will fire in the main process (web) before it hits the queue
        Log::info('JOB_CONSTRUCTED: Data sent to queue', [
            'id' => $this->document->id,
            'processed_at' => $this->document->processed_at
        ]);
    }

    public function handle(VectorService $vectorService)
    {
        // LOG 1: Absolute first line of the worker execution
        Log::info('JOB_HANDLE_STARTED', ['id' => $this->document->id]);

        $embedding = $vectorService->getEmbedding($this->document->content);

        if ($embedding) {
            $this->document->updateQuietly(['embedding' => $embedding]);

            Log::info('JOB_EVENT_FIRING', [
                'id' => $this->document->id,
                'processed_at' => $this->document->processed_at
            ]);

            event(new \App\Events\DocumentVectorized($this->document));
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('JOB_CRASHED', [
            'id' => $this->document->id,
            'message' => $exception->getMessage()
        ]);
    }
}
