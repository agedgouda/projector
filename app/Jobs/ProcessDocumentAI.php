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

        // Case 2: AI Error handling
        if ($result['status'] === 'error') {
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        event(new DocumentProcessingUpdate($this->document, 'Generating project deliverables...', 65));

        $generatedItems = $result['mock_response'] ?? [];
        $outputType = $result['output_type'];

        DB::transaction(function () use ($generatedItems, $outputType) {
            $this->document->project->documents()
                ->where('parent_id', $this->document->id)
                ->where('type', $outputType)
                ->delete();

            foreach ($generatedItems as $data) {
                // 1. Extract the specific content using the dynamic key
                $content = $data[$outputType] ?? null;

                // 2. Fail if the content is missing
                if (empty($content)) {
                    throw new \Exception("AI Validation Error: Required key '{$outputType}' was missing from the response.");
                }

                $this->document->project->documents()->create([
                    'parent_id'    => $this->document->id,
                    'type'         => $outputType,
                    'name'         => $data['title'] ?? 'Untitled Deliverable',
                    'content'      => $content,
                    'metadata'     => [
                        'criteria' => $data['criteria'] ?? [],
                        'category' => $data['category'] ?? 'general',
                    ],
                ]);
            }

            $this->document->update(['processed_at' => now()]);
        });

        event(new DocumentProcessingUpdate($this->document, 'Success', 100));
    }

    /**
     * Final cleanup if all retries are exhausted.
     */
    public function failed(Throwable $exception)
    {
        Log::error("AI Job Exhausted Retries: " . $exception->getMessage());

        if (!$this->document->processed_at) {
            $this->document->update(['processed_at' => now()]);
        }

        event(new DocumentProcessingUpdate(
            $this->document,
            'AI Service Failed after multiple attempts: ' . $exception->getMessage(),
            0
        ));
    }
}
