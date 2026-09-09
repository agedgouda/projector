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
     * @param  int|null  $aiTemplateId  Set when this pass was driven by a saved transformation
     *                                  (an AiTemplate with type 'spreadsheet_import') — stamped onto every created row's
     *                                  last_ai_template_id, the same provenance field ProcessDocumentAI already sets for
     *                                  every other AI-produced document, so "which transformation made this" works the same
     *                                  way here as it does everywhere else. Null for an ad-hoc (never-saved) import.
     * @param  int|null  $confirmedMappingId  The ProjectImportMapping row this pass's mapping was
     *                                        resolved against, if any (see FileImportProcessor and
     *                                        ImportTransformationController::applySpreadsheet()) —
     *                                        events only (see importEvents()), this is what "the
     *                                        recipe owns its data" means: every re-import under the
     *                                        same recipe replaces every event that recipe has ever
     *                                        produced, rather than trying to match rows one at a
     *                                        time by name + date, which breaks the moment a row's
     *                                        name changes (an event has no other stable identity to
     *                                        key on). Null for a plain manual import (no recipe to
     *                                        own anything), which keeps the name + date matching
     *                                        importTasks() also uses.
     */
    public function __construct(
        public Document $importDocument,
        public string $listType,
        public array $headers,
        public array $rows,
        public array $mapping,
        public ?int $aiTemplateId = null,
        public ?int $confirmedMappingId = null,
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
            $this->finish($project, [], [], [], 0, 0, '?tab=tasks', 'tasks');

            return;
        }

        $kanbanColumns = $project->kanbanColumns;
        $defaultColumn = $kanbanColumns->first();
        $defaultStatus = $defaultColumn !== null ? $defaultColumn->key : 'todo';
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();
        $existingByKey = $this->existingDocumentsByKey($project, 'task', 'due_at');

        $normalizedRows = [];
        $skipped = [];
        $untaggedRows = [];
        $createdCount = 0;
        $updatedCount = 0;
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
            $rawTag = trim($cell('tag'));
            $tag = $importService->findOrCreateTag($rawTag ?: null, $familyRoot, $categories);
            if ($tag === null && $rawTag !== '') {
                $untaggedRows[] = ['row' => $index + 2, 'tag' => $rawTag];
            }

            $normalizedRows[] = [
                'name' => $name,
                'priority' => $priority,
                'task_status' => $taskStatus,
                'due_at' => $dueAt,
                'assignee' => $assigneeText ?: null,
                'tag' => $tag?->name,
            ];

            try {
                $task = $existingByKey[$this->matchKey($name, $dueAt)] ?? $project->documents()->make();
                $isUpdate = $task->exists;

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
                    'last_ai_template_id' => $this->aiTemplateId,
                    'last_output_key' => $this->aiTemplateId !== null ? $this->listType : null,
                ]);
                $task->save();

                if ($tag !== null) {
                    $task->categories()->sync([$tag->id]);
                }

                $isUpdate ? $updatedCount++ : $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 2, 'reason' => $e->getMessage()];
            }

            $this->maybeBroadcastProgress($index + 1, $total);
        }

        $this->finish($project, $normalizedRows, $skipped, $untaggedRows, $createdCount, $updatedCount, '?tab=tasks', 'tasks');
    }

    private function importEvents(Project $project, TaskListImportService $importService): void
    {
        $familyRoot = $project->familyRoot();
        $categories = $project->familyCategories();

        // A confirmed recipe (a header row + mapping a human has approved — see
        // ProjectImportMapping) owns every event it has ever produced, rather than each row
        // trying to re-match an existing event by name + date: a renamed row has no other
        // stable identity to match against, and previously that left the old copy behind as an
        // orphan instead of replacing it. So every re-import under the same recipe drops that
        // recipe's entire prior output first and recreates it wholesale from the current rows —
        // simple and correct as long as the spreadsheet is treated as the source of truth (any
        // manual edit made to one of these events in Projector is lost on the next re-import).
        // A plain manual import with no recipe (importDocument wasn't produced through a
        // confirmed mapping) keeps the old name + date upsert matching instead.
        $ownedByRecipe = $this->confirmedMappingId !== null;
        $existingByKey = $ownedByRecipe ? [] : $this->existingDocumentsByKey($project, 'event', 'start_at');

        $droppedCount = 0;
        if ($ownedByRecipe) {
            $recipeOwnedEvents = Document::where('project_id', $project->id)
                ->where('type', 'event')
                ->where('metadata->source_mapping_id', $this->confirmedMappingId);

            $droppedCount = $recipeOwnedEvents->count();
            $recipeOwnedEvents->delete();
        }

        $normalizedRows = [];
        $skipped = [];
        $untaggedRows = [];
        $createdCount = 0;
        $updatedCount = 0;
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

            $rawTag = trim($cell('tag'));
            $tag = $importService->findOrCreateTag($rawTag ?: null, $familyRoot, $categories);
            if ($tag === null && $rawTag !== '') {
                $untaggedRows[] = ['row' => $index + 2, 'tag' => $rawTag];
            }

            $normalizedRows[] = [
                'name' => $name,
                'description' => $description,
                'start_date' => $startAt,
                'due_date' => $dueAt,
                'tag' => $tag?->name,
            ];

            try {
                $event = $ownedByRecipe ? $project->documents()->make() : ($existingByKey[$this->matchKey($name, $startAt)] ?? $project->documents()->make());
                $isUpdate = $event->exists;

                $metadata = ['imported_from' => $this->importDocument->id];
                if ($ownedByRecipe) {
                    $metadata['source_mapping_id'] = $this->confirmedMappingId;
                }

                $event->forceFill([
                    'type' => 'event',
                    'name' => $name,
                    'content' => $description,
                    'start_at' => $startAt,
                    'due_at' => $dueAt,
                    'metadata' => $metadata,
                    'last_ai_template_id' => $this->aiTemplateId,
                    'last_output_key' => $this->aiTemplateId !== null ? $this->listType : null,
                ]);
                $event->save();

                if ($tag !== null) {
                    $event->categories()->sync([$tag->id]);
                }

                $isUpdate ? $updatedCount++ : $createdCount++;
            } catch (Throwable $e) {
                $skipped[] = ['row' => $index + 2, 'reason' => $e->getMessage()];
            }

            $this->maybeBroadcastProgress($index + 1, $total);
        }

        $this->finish($project, $normalizedRows, $skipped, $untaggedRows, $createdCount, $updatedCount, '?tab=calendar', 'events', $droppedCount);
    }

    /**
     * A snapshot of this project's existing tasks/events, keyed by matchKey() (name + the
     * type's anchor date — due_at for tasks, start_at for events) so a row from a re-imported,
     * slightly-changed spreadsheet updates the task/event it already produced last time instead
     * of creating a duplicate. Taken once up front (a single query) rather than queried per row,
     * and never updated mid-loop — two rows in the *same* file that happen to share a name and
     * date each still get their own new document, matching only against what existed before this
     * import ran.
     *
     * @return array<string, Document>
     */
    private function existingDocumentsByKey(Project $project, string $type, string $dateColumn): array
    {
        return $project->documents()
            ->where('type', $type)
            ->get(['id', 'name', $dateColumn])
            ->keyBy(function (Document $document) use ($dateColumn) {
                $rawDate = $document->getAttribute($dateColumn);

                return $this->matchKey($document->name ?? '', is_string($rawDate) ? $rawDate : null);
            })
            ->all();
    }

    /**
     * $date is either a 'Y-m-d' string (from TaskListImportService::parseDate(), used while
     * scanning the incoming rows) or whatever raw value Eloquent hands back for a timestamp
     * column (used while indexing existing documents) — both are normalized through Carbon so a
     * row's parsed date and a previously-stored timestamp compare equal regardless of format.
     */
    private function matchKey(string $name, ?string $date): string
    {
        $normalizedDate = $date !== null && $date !== ''
            ? \Illuminate\Support\Carbon::parse($date)->toDateString()
            : '';

        return mb_strtolower(trim($name)).'|'.$normalizedDate;
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
     * @param  list<array{row: int, tag: string}>  $untaggedRows
     */
    private function finish(Project $project, array $normalizedRows, array $skipped, array $untaggedRows, int $createdCount, int $updatedCount, string $redirectQuery, string $noun, int $droppedCount = 0): void
    {
        $this->importDocument->update([
            'content' => json_encode($normalizedRows, JSON_PRETTY_PRINT),
            'metadata' => [
                'original_filename' => $this->importDocument->metadata['original_filename'] ?? null,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'dropped_count' => $droppedCount,
                'skipped' => $skipped,
                'untagged' => $untaggedRows,
                'status' => $skipped === [] ? 'completed' : 'completed_with_errors',
            ],
            // task_list_import/event_list_import documents are never a "task" per
            // DocumentTypeDefinition's catalog, so DocumentObserver::creating() never stamps
            // processed_at the way it does for a real generated task — left null forever, this
            // makes TraceabilityRow.vue/TaskRowContent.vue's shared isProcessing check
            // (`processed_at === null`) show every import as permanently "Processing...", success
            // or failure, regardless of what metadata.status actually says.
            'processed_at' => now(),
        ]);

        $summary = "Imported {$createdCount} {$noun}"
            .($updatedCount > 0 ? ", updated {$updatedCount}" : '')
            .($droppedCount > 0 ? ", removed {$droppedCount} stale" : '')
            .'.';
        $message = $skipped === []
            ? $summary
            : rtrim($summary, '.').', skipped '.count($skipped).' row(s) — see the import record for details.';

        // findOrCreateTag() deliberately leaves a row untagged rather than failing the import
        // once every palette color is already in use by an existing tag on the project (see
        // TaskListImportService::findOrCreateTag()) — surfaced here as a toast (not just
        // buried in the import record's metadata) since it silently drops data the sheet
        // actually specified, unlike a row that was simply left blank.
        $warning = null;
        if ($untaggedRows !== []) {
            $untaggedCount = count($untaggedRows);
            $untaggedNoun = $untaggedCount === 1 ? rtrim($noun, 's') : $noun;
            $warning = "{$untaggedCount} {$untaggedNoun} didn't get a tag — the project has used up all available tag colors. Free up a color (or add the tag manually) and try again.";
        }

        $redirectUrl = route('projects.show', $project).$redirectQuery;
        $total = count($this->rows);

        event(new TaskListImportProgress($this->importDocument, $total, $total, 'done', $redirectUrl, $message, $warning));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Task list import failed: '.$exception->getMessage());

        $this->importDocument->update([
            'metadata' => array_merge($this->importDocument->metadata ?? [], [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]),
            'processed_at' => now(),
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
