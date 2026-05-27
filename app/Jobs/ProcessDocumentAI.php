<?php

namespace App\Jobs;

use App\Events\DocumentProcessingUpdate;
use App\Models\Document;
use App\Services\Ai\ProjectAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\CommonMark\CommonMarkConverter;
use Throwable;

class ProcessDocumentAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 5;

    public $timeout = 300;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        $this->document->loadMissing('project.client.organization');
        $this->document->project->client->organization?->applyDriverConfig();

        /** @var ProjectAiService $aiService */
        $aiService = app(ProjectAiService::class);

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

        $outputType = $result['output_type'];
        $singleOutput = $result['single_output'] ?? false;

        DB::transaction(function () use ($result, $outputType, $singleOutput) {
            $this->document->project->documents()
                ->where('parent_id', $this->document->id)
                ->where('type', $outputType)
                ->delete();

            if ($singleOutput) {
                $doc = $result['mock_response'] ?? [];
                $markdown = $doc['content'] ?? null;

                if (empty($markdown)) {
                    throw new \Exception("AI Validation Error: Single-output response was missing 'content'.");
                }

                $html = (new CommonMarkConverter)->convert($markdown)->getContent();

                $this->document->project->documents()->create([
                    'parent_id' => $this->document->id,
                    'type' => $outputType,
                    'name' => $doc['title'] ?? ($this->document->name.' — Requirements'),
                    'content' => $html,
                ]);
            } else {
                foreach ($result['mock_response'] ?? [] as $data) {
                    $content = $data[$outputType] ?? null;

                    if (empty($content)) {
                        throw new \Exception("AI Validation Error: Required key '{$outputType}' was missing from the response.");
                    }

                    $dueAt = ! empty($data['due_date']) ? \Illuminate\Support\Carbon::parse($data['due_date'])->toDateString() : null;

                    $this->document->project->documents()->create([
                        'parent_id' => $this->document->id,
                        'type' => $outputType,
                        'name' => $data['title'] ?? 'Untitled Deliverable',
                        'content' => $content,
                        'due_at' => $dueAt,
                        'metadata' => [
                            'criteria' => $data['criteria'] ?? [],
                            'category' => $data['category'] ?? 'general',
                        ],
                    ]);
                }
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
        Log::error('AI Job Exhausted Retries: '.$exception->getMessage());

        if (! $this->document->processed_at) {
            $this->document->update(['processed_at' => now()]);
        }

        event(new DocumentProcessingUpdate(
            $this->document,
            'AI Service Failed after multiple attempts: '.$exception->getMessage(),
            0
        ));
    }
}
