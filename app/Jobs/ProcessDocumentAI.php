<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ai\ProjectAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Document $document) {}

    public function handle(ProjectAiService $aiService)
    {
        \Log::info("Starting AI Process for Document: {$this->document->id}");

        // 1. Run the actual API call
        $results = $aiService->process($this->document);

        // 2. Instead of saving, we LOG it so you can inspect the JSON
        \Log::info("AI Generation Results for {$this->document->name}:", [
            'count' => count($results['mock_response'] ?? []),
            'data'  => $results['mock_response']
        ]);

        // 3. Mark as processed so the Observer doesn't loop
        $this->document->update(['processed_at' => now()]);

        \Log::info("Process complete for Document: {$this->document->id}");
    }
}
