<?php

namespace App\Jobs;

use App\Events\TaskListImportProgress;
use App\Models\Document;
use App\Models\Project;
use App\Services\Ai\TextExtractionService;
use App\Services\TaskListImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The text-source counterpart to ImportTaskList: instead of resolving a column mapping against
 * already-parsed CSV rows, this makes one AI extraction call (TextExtractionService::extract())
 * against the full source text and creates documents from whatever records it returns. Kept as
 * its own job — rather than folded into ImportTaskList — because there's no per-row mapping
 * step here at all; the AI already returns typed field values, not row indices to resolve.
 */
class ExtractTextRecords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public Document $importDocument,
        public string $listType,
        public string $sourceText,
        public string $extractionRule,
        public ?int $aiTemplateId = null,
    ) {}

    public function handle(TextExtractionService $extractionService, TaskListImportService $importService): void
    {
        $project = $this->importDocument->project;
        if (! $project instanceof Project) {
            // Document.project_id is a required FK — this is unreachable in practice, but the
            // relation's own type is nullable, so this satisfies that rather than asserting it
            // away.
            return;
        }

        $organizationId = $project->client?->organization_id;

        $result = $extractionService->extract($this->sourceText, $this->listType, $this->extractionRule, $organizationId);

        $this->listType === 'event'
            ? $this->createEvents($project, $importService, $result['records'])
            : $this->createTasks($project, $importService, $result['records']);
    }

    /**
     * @param  array<int, array{
     *     name: string, priority: string|null, task_status: string|null, due_at: string|null,
     *     assignee: string|null, start_date: string|null, description: string|null, tag: string|null
     * }>  $records
     */
    private function createTasks(Project $project, TaskListImportService $importService, array $records): void
    {
        $project->loadMissing('client.organization.users', 'client.organization.invitations', 'kanbanColumns');
        $organization = $project->client?->organization;

        if ($organization === null) {
            $this->finish([], [], [], 0, '?tab=tasks', 'tasks');

            return;
        }

        $kanbanColumns = $project->kanbanColumns;
        $defaultColumn = $kanbanColumns->first();
        $defaultStatus = $defaultColumn !== null ? $defaultColumn->key : 'todo';
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();

        $normalizedRecords = [];
        $skipped = [];
        $untaggedRecords = [];
        $createdCount = 0;

        foreach ($records as $index => $record) {
            $name = trim($record['name']);
            if ($name === '') {
                $skipped[] = ['row' => $index + 1, 'reason' => 'Missing task name.'];

                continue;
            }

            $priority = $importService->normalizePriority($record['priority'] ?? '');
            $taskStatus = $importService->normalizeStatus($record['task_status'] ?? '', $kanbanColumns, $defaultStatus);
            $dueAt = $importService->parseDate($record['due_at'] ?? '');
            $assigneeText = trim($record['assignee'] ?? '');
            $assignee = $importService->resolveAssignee($assigneeText ?: null, $organization->users, $organization->invitations);
            $rawTag = trim($record['tag'] ?? '');
            $tag = $importService->findOrCreateTag($rawTag ?: null, $familyRoot, $categories);
            if ($tag === null && $rawTag !== '') {
                $untaggedRecords[] = ['row' => $index + 1, 'tag' => $rawTag];
            }

            $normalizedRecords[] = [
                'name' => $name,
                'priority' => $priority,
                'task_status' => $taskStatus,
                'due_at' => $dueAt,
                'assignee' => $assigneeText ?: null,
                'tag' => $tag?->name,
            ];

            try {
                $task = $project->documents()->make();
                // See ImportTaskList::importTasks() for why status/task_status are forceFill'd
                // together — the same legacy-column quirk applies here.
                $task->forceFill([
                    'type' => 'task',
                    'name' => $name,
                    'content' => trim($record['description'] ?? ''),
                    'priority' => $priority,
                    'status' => $taskStatus,
                    'task_status' => $taskStatus,
                    'due_at' => $dueAt,
                    'assignee_id' => $assignee['assignee_id'],
                    'pending_assignee_invitation_id' => $assignee['pending_assignee_invitation_id'],
                    'metadata' => ['imported_from' => $this->importDocument->id],
                    'last_ai_template_id' => $this->aiTemplateId,
                    'last_output_key' => $this->aiTemplateId !== null ? $this->listType : null,
                ]);
                $task->save();

                if ($tag !== null) {
                    $task->categories()->sync([$tag->id]);
                }

                $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 1, 'reason' => $e->getMessage()];
            }
        }

        $this->finish($normalizedRecords, $skipped, $untaggedRecords, $createdCount, '?tab=tasks', 'tasks');
    }

    /**
     * @param  array<int, array{
     *     name: string, priority: string|null, task_status: string|null, due_at: string|null,
     *     assignee: string|null, start_date: string|null, description: string|null, tag: string|null
     * }>  $records
     */
    private function createEvents(Project $project, TaskListImportService $importService, array $records): void
    {
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();

        $normalizedRecords = [];
        $skipped = [];
        $untaggedRecords = [];
        $createdCount = 0;

        foreach ($records as $index => $record) {
            $name = trim($record['name']);
            if ($name === '') {
                $skipped[] = ['row' => $index + 1, 'reason' => 'Missing event name.'];

                continue;
            }

            $description = trim($record['description'] ?? '');
            $startAt = $importService->parseDate($record['start_date'] ?? '');
            $dueAt = $importService->parseDate($record['due_at'] ?? '');

            // Same one-day-event rule as ImportTaskList::importEvents() and the "Notes to
            // Events" AI transformation: a record with only one date gets that same date for
            // both ends of the range.
            if ($startAt !== null && $dueAt === null) {
                $dueAt = $startAt;
            } elseif ($dueAt !== null && $startAt === null) {
                $startAt = $dueAt;
            }

            $rawTag = trim($record['tag'] ?? '');
            $tag = $importService->findOrCreateTag($rawTag ?: null, $familyRoot, $categories);
            if ($tag === null && $rawTag !== '') {
                $untaggedRecords[] = ['row' => $index + 1, 'tag' => $rawTag];
            }

            $normalizedRecords[] = [
                'name' => $name,
                'description' => $description,
                'start_date' => $startAt,
                'due_date' => $dueAt,
                'tag' => $tag?->name,
            ];

            try {
                $event = $project->documents()->create([
                    'type' => 'event',
                    'name' => $name,
                    'content' => $description,
                    'start_at' => $startAt,
                    'due_at' => $dueAt,
                    'metadata' => ['imported_from' => $this->importDocument->id],
                    'last_ai_template_id' => $this->aiTemplateId,
                    'last_output_key' => $this->aiTemplateId !== null ? $this->listType : null,
                ]);

                if ($tag !== null) {
                    $event->categories()->sync([$tag->id]);
                }

                $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 1, 'reason' => $e->getMessage()];
            }
        }

        $this->finish($normalizedRecords, $skipped, $untaggedRecords, $createdCount, '?tab=calendar', 'events');
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedRecords
     * @param  list<array{row: int, reason: string}>  $skipped
     * @param  list<array{row: int, tag: string}>  $untaggedRecords
     */
    private function finish(array $normalizedRecords, array $skipped, array $untaggedRecords, int $createdCount, string $redirectQuery, string $noun): void
    {
        $this->importDocument->update([
            'content' => json_encode($normalizedRecords, JSON_PRETTY_PRINT),
            'metadata' => [
                'original_filename' => $this->importDocument->metadata['original_filename'] ?? null,
                'created_count' => $createdCount,
                'skipped' => $skipped,
                'untagged' => $untaggedRecords,
                'status' => $skipped === [] ? 'completed' : 'completed_with_errors',
            ],
        ]);

        $message = $skipped === []
            ? "Imported {$createdCount} {$noun}."
            : "Imported {$createdCount} {$noun}, skipped ".count($skipped).' record(s) — see the import record for details.';

        $warning = null;
        if ($untaggedRecords !== []) {
            $untaggedCount = count($untaggedRecords);
            $untaggedNoun = $untaggedCount === 1 ? rtrim($noun, 's') : $noun;
            $warning = "{$untaggedCount} {$untaggedNoun} didn't get a tag — the project has used up all available tag colors. Free up a color (or add the tag manually) and try again.";
        }

        $redirectUrl = route('projects.show', $this->importDocument->project).$redirectQuery;

        event(new TaskListImportProgress($this->importDocument, 1, 1, 'done', $redirectUrl, $message, $warning));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Text record extraction failed: '.$exception->getMessage());

        $this->importDocument->update([
            'metadata' => array_merge($this->importDocument->metadata ?? [], [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]),
        ]);

        event(new TaskListImportProgress(
            $this->importDocument,
            0,
            1,
            'error',
            null,
            'The extraction failed: '.$exception->getMessage()
        ));
    }
}
