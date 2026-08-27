<?php

namespace App\Jobs;

use App\Events\TaskListImportProgress;
use App\Models\Document;
use App\Models\Project;
use App\Services\TaskListImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportTaskList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 600;

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, string|null>  $mapping
     */
    public function __construct(
        public Document $importDocument,
        public string $listType,
        public array $headers,
        public array $rows,
        public array $mapping,
    ) {}

    public function handle(TaskListImportService $importService): void
    {
        $project = $this->importDocument->project;

        $this->listType === 'event'
            ? $this->importEvents($project, $importService)
            : $this->importTasks($project, $importService);
    }

    private function importTasks(Project $project, TaskListImportService $importService): void
    {
        $project->loadMissing('client.organization.users', 'client.organization.invitations', 'kanbanColumns');
        $organization = $project->client?->organization;

        if ($organization === null) {
            $this->finish($project, [], [], 0, '?tab=tasks', 'tasks');

            return;
        }

        $kanbanColumns = $project->kanbanColumns;
        $defaultColumn = $kanbanColumns->first();
        $defaultStatus = $defaultColumn !== null ? $defaultColumn->key : 'todo';
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();

        $normalizedRows = [];
        $skipped = [];
        $createdCount = 0;
        $total = count($this->rows);

        foreach ($this->rows as $index => $row) {
            $cell = fn (string $field): string => $importService->cellFor($row, $this->headers, $this->mapping, $field);

            $name = trim($cell('name'));
            if ($name === '') {
                $skipped[] = ['row' => $index + 2, 'reason' => 'Missing task name.'];
                $this->maybeBroadcastProgress($index + 1, $total);

                continue;
            }

            $priority = $importService->normalizePriority($cell('priority'));
            $taskStatus = $importService->normalizeStatus($cell('task_status'), $kanbanColumns, $defaultStatus);
            $dueAt = $importService->parseDate($cell('due_at'));
            $assigneeText = $cell('assignee');
            $assignee = $importService->resolveAssignee($assigneeText ?: null, $organization->users, $organization->invitations);
            $tag = $importService->findOrCreateTag($cell('tag') ?: null, $familyRoot, $categories);

            $normalizedRows[] = [
                'name' => $name,
                'priority' => $priority,
                'task_status' => $taskStatus,
                'due_at' => $dueAt,
                'assignee' => $assigneeText ?: null,
                'tag' => $tag?->name,
            ];

            try {
                $task = $project->documents()->make();
                // DocumentObserver::creating() defaults task_status to 'todo' whenever the
                // legacy `status` column is null — forceFill both together (status isn't
                // fillable, it predates task_status and nothing else still writes to it) so
                // the mapped status from the sheet survives instead of being silently
                // overwritten the moment this saves.
                $task->forceFill([
                    'type' => 'task',
                    'name' => $name,
                    'content' => '',
                    'priority' => $priority,
                    'status' => $taskStatus,
                    'task_status' => $taskStatus,
                    'due_at' => $dueAt,
                    'assignee_id' => $assignee['assignee_id'],
                    'pending_assignee_invitation_id' => $assignee['pending_assignee_invitation_id'],
                    'metadata' => ['imported_from' => $this->importDocument->id],
                ]);
                $task->save();

                if ($tag !== null) {
                    $task->categories()->sync([$tag->id]);
                }

                $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 2, 'reason' => $e->getMessage()];
            }

            $this->maybeBroadcastProgress($index + 1, $total);
        }

        $this->finish($project, $normalizedRows, $skipped, $createdCount, '?tab=tasks', 'tasks');
    }

    private function importEvents(Project $project, TaskListImportService $importService): void
    {
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();

        $normalizedRows = [];
        $skipped = [];
        $createdCount = 0;
        $total = count($this->rows);

        foreach ($this->rows as $index => $row) {
            $cell = fn (string $field): string => $importService->cellFor($row, $this->headers, $this->mapping, $field);

            $name = trim($cell('name'));
            if ($name === '') {
                $skipped[] = ['row' => $index + 2, 'reason' => 'Missing event name.'];
                $this->maybeBroadcastProgress($index + 1, $total);

                continue;
            }

            $description = trim($cell('description'));
            $startAt = $importService->parseDate($cell('start_date'));
            $dueAt = $importService->parseDate($cell('due_at'));

            // A row with only one of the two dates is a one-day event — the same date is both
            // the start and the end, matching the "Notes to Events" AI transformation's own
            // rule for a single date mentioned in the source.
            if ($startAt !== null && $dueAt === null) {
                $dueAt = $startAt;
            } elseif ($dueAt !== null && $startAt === null) {
                $startAt = $dueAt;
            }

            $tag = $importService->findOrCreateTag($cell('tag') ?: null, $familyRoot, $categories);

            $normalizedRows[] = [
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
                ]);

                if ($tag !== null) {
                    $event->categories()->sync([$tag->id]);
                }

                $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 2, 'reason' => $e->getMessage()];
            }

            $this->maybeBroadcastProgress($index + 1, $total);
        }

        $this->finish($project, $normalizedRows, $skipped, $createdCount, '?tab=calendar', 'events');
    }

    /**
     * Broadcasts roughly 50 updates total over the course of the import regardless of its
     * size — frequent enough to feel live for a small list, infrequent enough that a 5000-row
     * import (the validated max) doesn't spend real time on broadcast round-trips between
     * nearly every row. Always fires on the first row too (so a real, changing "X of Y" shows
     * up as early as possible rather than the client's first update being the final, equal
     * one) and on the last row (so the final count is never stale).
     */
    private function maybeBroadcastProgress(int $processed, int $total): void
    {
        $every = max(1, (int) floor($total / 50));

        if ($processed !== 1 && $processed !== $total && $processed % $every !== 0) {
            return;
        }

        event(new TaskListImportProgress($this->importDocument, $processed, $total, 'running'));
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedRows
     * @param  list<array{row: int, reason: string}>  $skipped
     */
    private function finish(Project $project, array $normalizedRows, array $skipped, int $createdCount, string $redirectQuery, string $noun): void
    {
        $this->importDocument->update([
            'content' => json_encode($normalizedRows, JSON_PRETTY_PRINT),
            'metadata' => [
                'original_filename' => $this->importDocument->metadata['original_filename'] ?? null,
                'created_count' => $createdCount,
                'skipped' => $skipped,
                'status' => $skipped === [] ? 'completed' : 'completed_with_errors',
            ],
        ]);

        $message = $skipped === []
            ? "Imported {$createdCount} {$noun}."
            : "Imported {$createdCount} {$noun}, skipped ".count($skipped).' row(s) — see the import record for details.';

        $redirectUrl = route('projects.show', $project).$redirectQuery;
        $total = count($this->rows);

        event(new TaskListImportProgress($this->importDocument, $total, $total, 'done', $redirectUrl, $message));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Task list import failed: '.$exception->getMessage());

        $this->importDocument->update([
            'metadata' => array_merge($this->importDocument->metadata ?? [], [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]),
        ]);

        event(new TaskListImportProgress(
            $this->importDocument,
            0,
            count($this->rows),
            'error',
            null,
            'The import failed: '.$exception->getMessage()
        ));
    }
}
