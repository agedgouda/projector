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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Throwable;

class ProcessDocumentAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 5;

    public $timeout = 300;

    /**
     * @param  array{to_key: string, ai_template_id: int, single_output?: bool, project_type_id?: string|null}|null  $overrideStep
     */
    public function __construct(public Document $document, public ?array $overrideStep = null) {}

    /**
     * Dispatch this job unless the same document already has a run in flight — a document
     * can only ever be processed by one of these at a time. Without this, two transition/
     * reprocess requests fired moments apart (a double-click, or a retry after no visible
     * feedback) each fully complete in turn, and the second silently overwrites whatever the
     * first produced (children are deleted and recreated on every run) with a fresh — and not
     * necessarily equivalent — AI response.
     *
     * @param  array{to_key: string, ai_template_id: int, single_output?: bool, project_type_id?: string|null}|null  $overrideStep
     */
    public static function dispatchUnlessProcessing(Document $document, ?array $overrideStep = null): bool
    {
        if (! Cache::lock(self::lockKey($document->id), 300)->get()) {
            return false;
        }

        self::dispatch($document, $overrideStep);

        return true;
    }

    private static function lockKey(string $documentId): string
    {
        return "document-processing:{$documentId}";
    }

    public function handle(): void
    {
        $this->document->loadMissing('project.client.organization');
        $this->document->project->client->organization?->applyDriverConfig();

        /** @var ProjectAiService $aiService */
        $aiService = app(ProjectAiService::class);

        event(new DocumentProcessingUpdate($this->document, 'Analyzing document...', 15));

        $result = $aiService->process($this->document, $this->overrideStep);

        // Case 1: Early return (Workflow/Template missing)
        if ($result === null) {
            $this->document->update(['processed_at' => now()]);
            event(new DocumentProcessingUpdate($this->document, 'Skipped: No template', 100));
            Cache::lock(self::lockKey($this->document->id))->forceRelease();

            return;
        }

        // Case 2: AI Error handling
        if ($result['status'] === 'error') {
            throw new \Exception($result['message'] ?? 'AI transformation failed');
        }

        event(new DocumentProcessingUpdate($this->document, 'Generating project deliverables...', 65));

        $outputType = $result['output_type'];
        $singleOutput = $result['single_output'] ?? false;
        $lockedProjectTypeId = $result['locked_project_type_id'] ?? null;

        $deletedDocumentIds = [];
        $newDocumentIds = [];

        DB::transaction(function () use ($result, $outputType, $singleOutput, $lockedProjectTypeId, &$deletedDocumentIds, &$newDocumentIds) {
            // Reprocessing replaces all previously generated children, even if the
            // output type has changed since the last run, so nothing is left behind.
            $deletedDocumentIds = $this->descendantIds($this->document->id);

            $this->document->project->documents()
                ->where('parent_id', $this->document->id)
                ->delete();

            if ($singleOutput) {
                $doc = $result['mock_response'] ?? [];
                $markdown = $doc['content'] ?? null;

                if (empty($markdown)) {
                    throw new \Exception("AI Validation Error: Single-output response was missing 'content'.");
                }

                $html = (new GithubFlavoredMarkdownConverter)->convert($markdown)->getContent();

                $newDocumentIds[] = $this->document->project->documents()->create([
                    'parent_id' => $this->document->id,
                    'type' => $outputType,
                    'name' => $doc['title'] ?? ($this->document->name.' — Requirements'),
                    'content' => $html,
                    'locked_project_type_id' => $lockedProjectTypeId,
                ])->id;
            } else {
                foreach ($result['mock_response'] ?? [] as $data) {
                    $content = $data[$outputType] ?? null;

                    if (empty($content)) {
                        throw new \Exception("AI Validation Error: Required key '{$outputType}' was missing from the response.");
                    }

                    $dueAt = ! empty($data['due_date']) ? \Illuminate\Support\Carbon::parse($data['due_date'])->toDateString() : null;

                    // The AI is free to omit priority (e.g. templates that don't produce
                    // per-item tasks) or hallucinate an out-of-range value, so anything
                    // other than the three known levels falls back to the column default.
                    $priority = in_array($data['priority'] ?? null, ['low', 'medium', 'high'], true)
                        ? $data['priority']
                        : 'medium';

                    $newDocumentIds[] = $this->document->project->documents()->create([
                        'parent_id' => $this->document->id,
                        'type' => $outputType,
                        'name' => $data['title'] ?? 'Untitled Deliverable',
                        'content' => $content,
                        'due_at' => $dueAt,
                        'priority' => $priority,
                        'assignee_id' => $data['assignee_id'] ?? null,
                        'locked_project_type_id' => $lockedProjectTypeId,
                        'metadata' => [
                            'criteria' => $data['criteria'] ?? [],
                            'category' => $data['category'] ?? 'general',
                        ],
                    ])->id;
                }
            }

            $this->document->update(['processed_at' => now()]);
        });

        Cache::lock(self::lockKey($this->document->id))->forceRelease();
        event(new DocumentProcessingUpdate($this->document, 'Success', 100, $deletedDocumentIds, $newDocumentIds));
    }

    /**
     * Recursively collects the IDs of every descendant of the given document,
     * so the frontend can drop them from the traceability tree once they're deleted.
     *
     * @return array<int, string>
     */
    private function descendantIds(string $documentId): array
    {
        $ids = [];
        $frontier = [$documentId];

        while (true) {
            $children = Document::query()->whereIn('parent_id', $frontier)->pluck('id')->all();

            if (empty($children)) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
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

        Cache::lock(self::lockKey($this->document->id))->forceRelease();

        event(new DocumentProcessingUpdate(
            $this->document,
            'AI Service Failed after multiple attempts: '.$exception->getMessage(),
            0
        ));
    }
}
