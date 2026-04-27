<?php

namespace App\Jobs;

use App\Models\OrgDocument;
use App\Services\VectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateOrgDocumentEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public OrgDocument $orgDocument) {}

    public function handle(VectorService $vectorService): void
    {
        $this->orgDocument->loadMissing('organization');
        $this->orgDocument->organization->applyDriverConfig();

        try {
            $embedding = $vectorService->getEmbedding($this->orgDocument->content);

            if (! $embedding) {
                throw new \Exception('Vector Service returned empty embedding.');
            }

            $this->orgDocument->updateQuietly([
                'embedding' => $embedding,
                'processed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateOrgDocumentEmbedding failed', [
                'id' => $this->orgDocument->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
