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
        event(new DocumentProcessingUpdate($this->document, 'Analyzing document...', 15));

        $result = $aiService->process($this->document);

        // Case 1: Early return (Workflow/Template missing)
        if ($result === null) {
            $this->document->update(['processed_at' => now()]);
            event(new DocumentProcessingUpdate($this->document, 'Skipped: No template', 100));
            return;
        }

        // Case 2: AI Error handling with built-in retry logic
        if ($result['status'] === 'error') {
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        event(new DocumentProcessingUpdate($this->document, 'Generating project deliverables...', 65));

        // The Payload
        $generatedItems = $result['mock_response'] ?? [];
        $outputType = $result['output_type'];

        // Wrap everything in a transaction to ensure all-or-nothing delivery
        DB::transaction(function () use ($generatedItems, $outputType) {

            // 1. Precise Cleanup: Only remove previous AI-generated versions of this specific intake
            $this->document->project->documents()
                ->where('parent_id', $this->document->id)
                ->where('type', $outputType)
                ->delete();

            // 2. Bulk Creation: Let the Project handle the creation
            foreach ($generatedItems as $data) {
                $this->document->project->documents()->create([
                    'parent_id'    => $this->document->id,
                    'type'         => $outputType,
                    'name'         => $data['title'] ?? 'Untitled Deliverable',
                    'content'      => $data['story'] ?? $data['description'] ?? $data['content'] ?? '',
                    // Note: We do NOT set processed_at here.
                    // Why? Because we want the Observer to see them as "new content" and vectorize them.
                    'metadata'     => [
                        'criteria' => $data['criteria'] ?? [],
                        'category' => $data['category'] ?? 'general',
                    ],
                ]);
            }

            // 3. Mark the Intake as finished
            $this->document->update(['processed_at' => now()]);
        });

        event(new DocumentProcessingUpdate($this->document, 'Success', 100));
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
