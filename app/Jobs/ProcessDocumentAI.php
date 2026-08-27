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
     * @param  string|null  $oneOffInstructions  Free text entered on the Reprocess confirmation for this
     *                                           run only — never persisted, lives only in this job's
     *                                           own queued payload.
     */
    public function __construct(public Document $document, public ?array $overrideStep = null, public ?string $oneOffInstructions = null) {}

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
    public static function dispatchUnlessProcessing(Document $document, ?array $overrideStep = null, ?string $oneOffInstructions = null): bool
    {
        if (! Cache::lock(self::lockKey($document->id), 300)->get()) {
            return false;
        }

        self::dispatch($document, $overrideStep, $oneOffInstructions);

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

        // Named once the output type is resolved (fast and local — see ProjectAiService::process's
        // $onOutputTypeResolved doc) so the same "Generating {type}..." text carries the whole run
        // instead of flashing through a generic "Analyzing document..." first and a bare "Success"
        // at the end — both of which used to come and go too quickly to read.
        $typeLabel = null;

        $result = $aiService->process(
            $this->document,
            $this->overrideStep,
            $this->oneOffInstructions,
            function (string $outputType) use (&$typeLabel) {
                $typeLabel = $this->document->project?->documentTypeCatalog()->get($outputType)?->label ?? $outputType;
                event(new DocumentProcessingUpdate($this->document, "Generating {$typeLabel}...", 15));
            }
        );

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

        $outputType = $result['output_type'];
        $singleOutput = $result['single_output'] ?? false;
        $lockedProjectTypeId = $result['locked_project_type_id'] ?? null;

        // $typeLabel is already set — the callback above always runs before process() can return
        // a non-null, non-error result. Same text as the first event, just a progress bump.
        event(new DocumentProcessingUpdate($this->document, "Generating {$typeLabel}...", 65));

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

                foreach ($result['images'] ?? [] as $image) {
                    $src = $image['src'] ?? null;
                    if (! is_string($src) || $src === '') {
                        continue;
                    }
                    $alt = is_string($image['alt'] ?? null) ? $image['alt'] : '';
                    $html .= '<p><img src="'.e($src).'" alt="'.e($alt).'"></p>';
                }

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

                    $content = (string) $content;

                    foreach ($data['_images'] ?? [] as $image) {
                        $src = $image['src'] ?? null;
                        if (! is_string($src) || $src === '') {
                            continue;
                        }
                        $alt = is_string($image['alt'] ?? null) ? $image['alt'] : '';
                        $content .= '<p><img src="'.e($src).'" alt="'.e($alt).'"></p>';
                    }

                    $dueAt = ! empty($data['due_date']) ? \Illuminate\Support\Carbon::parse($data['due_date'])->toDateString() : null;
                    $startAt = ! empty($data['start_date']) ? \Illuminate\Support\Carbon::parse($data['start_date'])->toDateString() : null;

                    // The AI is free to omit priority (e.g. templates that don't produce
                    // per-item tasks) or hallucinate an out-of-range value, so anything
                    // other than the three known levels falls back to the column default.
                    $priority = in_array($data['priority'] ?? null, ['low', 'medium', 'high'], true)
                        ? $data['priority']
                        : 'medium';

                    $newDocument = $this->document->project->documents()->create([
                        'parent_id' => $this->document->id,
                        'type' => $outputType,
                        'name' => $data['title'] ?? 'Untitled Deliverable',
                        'content' => $content,
                        'due_at' => $dueAt,
                        'start_at' => $startAt,
                        'priority' => $priority,
                        'assignee_id' => $data['assignee_id'] ?? null,
                        'pending_assignee_invitation_id' => $data['pending_assignee_invitation_id'] ?? null,
                        'locked_project_type_id' => $lockedProjectTypeId,
                        'metadata' => [
                            'criteria' => $data['criteria'] ?? [],
                            'category' => $data['category'] ?? 'general',
                        ],
                    ]);
                    $newDocumentIds[] = $newDocument->id;

                    // Events mark a single occurrence on the calendar, so only one tag makes
                    // sense — same rule DocumentController::updateCategories() enforces for
                    // manual edits — everything else can carry any number the AI picked.
                    $categoryIds = $data['_category_ids'] ?? [];
                    if ($outputType === 'event') {
                        $categoryIds = array_slice($categoryIds, 0, 1);
                    }
                    if (! empty($categoryIds)) {
                        $newDocument->categories()->sync($categoryIds);
                    }
                }
            }

            $this->document->update([
                'processed_at' => now(),
                'last_ai_template_id' => $result['ai_template_id'] ?? null,
                'last_output_key' => isset($result['ai_template_id']) ? $outputType : null,
            ]);
        });

        Cache::lock(self::lockKey($this->document->id))->forceRelease();
        // Same text again, not the literal word "Success" — progress hitting 100 is what the
        // frontend actually keys success handling off of (see isSuccess in useAiProcessing.ts),
        // not the message string, so this can stay consistent with the two events above it.
        event(new DocumentProcessingUpdate($this->document, "Generating {$typeLabel}...", 100, $deletedDocumentIds, $newDocumentIds));
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
