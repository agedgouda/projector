<?php

namespace App\Jobs;

use App\Models\Document;
use App\Events\DocumentProcessingUpdate;
use App\Services\Ai\ProjectAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDocumentAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 5;

    public function __construct(public Document $document) {}

    public function handle(ProjectAiService $aiService)
    {
        // 1. Run the AI service logic
        $result = $aiService->process($this->document);

        // Case 1: Early return from service (workflow missing or template not found)
        if ($result === null) {
            $this->document->update(['processed_at' => now()]);
            Log::info("Job skipped: No valid workflow or template for Doc #{$this->document->id}");
            return;
        }

        // Case 2: AI Error (Handle Retries vs. Warnings)
        if ($result['status'] === 'error') {
            $attempt = $this->attempts();
            $msg = $result['message'] ?? 'Unknown AI Error';

            if ($attempt < $this->tries) {
                // Only log "Retrying" here, in the internal system logs
                Log::warning("AI Job Attempt #{$attempt} failed for Doc #{$this->document->id}. Retrying...", [
                    'reason' => $msg
                ]);

                // Throw exception to trigger internal queue retry
                throw new \Exception("{$msg} (Attempt {$attempt} of {$this->tries})");
            }

            // On the final attempt, throw the clean message
            throw new \Exception($msg);
        }

        // Case 3: Success - Persist the results
        $generatedItems = $result['mock_response'] ?? [];
        $outputType = $result['output_type'];

        DB::transaction(function () use ($generatedItems, $outputType) {
            // Cleanup: Remove previous attempts for this specific intake document
            Document::where('parent_id', $this->document->id)
                ->where('type', $outputType)
                ->delete();

            foreach ($generatedItems as $data) {
                // Determine content based on the expected strategy keys
                $content = $data['story'] ?? $data['description'] ?? $data['content'] ?? '';

                $this->document->project->documents()->create([
                    'parent_id'    => $this->document->id,
                    'project_id'   => $this->document->project_id,
                    'type'         => $outputType,
                    'name'         => $data['title'] ?? 'Untitled Deliverable',
                    'content'      => $content,
                    'processed_at' => now(),
                    'metadata'     => [
                        'criteria' => $data['criteria'] ?? [],
                        'category' => $data['category'] ?? 'general',
                        'raw_data' => $data
                    ],
                ]);
            }
        });

        // 4. Finalize the source document
        $this->document->update(['processed_at' => now()]);

        Log::info("AI Job Completed successfully for Document #{$this->document->id} on attempt #{$this->attempts()}");
    }

    /**
     * If all 3 tries fail, this method stops the spinner.
     */
    public function failed(Throwable $exception)
    {
        Log::error("AI Job Exhausted Retries for Doc #{$this->document->id}");

        $this->document->update([
            'processed_at' => now(),
            'metadata' => array_merge($this->document->metadata ?? [], [
                'error' => $exception->getMessage(),
                'failed_at' => now()
            ])
        ]);

        event(new DocumentProcessingUpdate(
        $this->document,
        'Error: ' . $exception->getMessage()
    ));
    }
}
