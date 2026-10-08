<?php

namespace App\Services\Tasks;

use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Links imported or generated tasks to the task each one waits on, given that task's name — a
 * spreadsheet's Predecessor column, or the predecessor an AI read from context. Names match
 * tasks from the same import first (an import's own rows are what it most likely means), then
 * the project's existing tasks, case-insensitively. Each link is saved like any other, so
 * TaskChainObserver dates the follower from its predecessor and cascades. Only for
 * organizations that track task start dates; otherwise nothing is linked.
 */
class TaskLinker
{
    public function __construct(private readonly TaskChainScheduler $scheduler) {}

    /**
     * @param  list<array{task: Document, predecessor: string, row?: int|null}>  $requests  in file/output order
     * @param  Collection<int, Document>  $importedTasks  every task this import created or updated
     * @return list<array{row: int|null, task: string, predecessor: string, reason: string}> links that couldn't be made
     */
    public function linkByName(Project $project, array $requests, Collection $importedTasks): array
    {
        if ($requests === [] || ! $project->usesTaskStartDatesFor('task')) {
            return [];
        }

        $catalog = $project->documentTypeCatalog();
        // Every task in the project, kept current as links are made below, so each loop check
        // sees the links made before it.
        $projectTasks = $project->documents()
            ->get(['id', 'name', 'type', 'predecessor_id'])
            ->filter(fn (Document $task) => $catalog->get($task->type)?->is_task === true)
            ->keyBy(fn (Document $task) => $this->scheduler->idOf($task));

        $importedIds = $importedTasks->map(fn (Document $task) => $this->scheduler->idOf($task))->flip();
        $importedByName = $this->firstByName($projectTasks->filter(fn (Document $task, string $id) => $importedIds->has($id)));
        $existingByName = $this->firstByName($projectTasks->reject(fn (Document $task, string $id) => $importedIds->has($id)));

        $unlinked = [];

        foreach ($requests as $request) {
            $task = $request['task'];
            $taskId = $this->scheduler->idOf($task);
            $wanted = trim($request['predecessor']);
            $key = $this->normalize($wanted);

            if ($key === '') {
                continue;
            }

            $predecessor = $importedByName[$key] ?? $existingByName[$key] ?? null;

            if ($predecessor === null) {
                $unlinked[] = $this->unlinked($request, "No task named \"{$wanted}\" in this import or the project.");

                continue;
            }

            $predecessorId = $this->scheduler->idOf($predecessor);
            if ($predecessorId === $taskId || in_array($predecessorId, $this->scheduler->descendantIds($projectTasks->values(), $taskId), true)) {
                $unlinked[] = $this->unlinked($request, "Linking to \"{$wanted}\" would make the tasks wait on each other.");

                continue;
            }

            $task->predecessor_id = $predecessorId;
            $task->save();
            $projectTasks->get($taskId)?->setAttribute('predecessor_id', $predecessorId);
        }

        return $unlinked;
    }

    /**
     * The project's task names, for telling an AI what an item could wait on.
     *
     * @return list<string>
     */
    public function existingTaskNames(Project $project): array
    {
        $catalog = $project->documentTypeCatalog();

        return array_values($project->documents()
            ->get(['name', 'type'])
            ->filter(fn (Document $task) => $catalog->get($task->type)?->is_task === true && filled($task->name))
            ->map(fn (Document $task) => (string) $task->name)
            ->unique()
            ->all());
    }

    /**
     * The first task with each (normalized) name.
     *
     * @param  Collection<string, Document>  $tasks
     * @return array<string, Document>
     */
    private function firstByName(Collection $tasks): array
    {
        $byName = [];
        foreach ($tasks as $task) {
            $byName[$this->normalize($task->name ?? '')] ??= $task;
        }

        return $byName;
    }

    /**
     * @param  array{task: Document, predecessor: string, row?: int|null}  $request
     * @return array{row: int|null, task: string, predecessor: string, reason: string}
     */
    private function unlinked(array $request, string $reason): array
    {
        return [
            'row' => $request['row'] ?? null,
            'task' => (string) $request['task']->name,
            'predecessor' => trim($request['predecessor']),
            'reason' => $reason,
        ];
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }
}
