<?php

namespace App\Services\Tasks;

use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Keeps linked tasks' dates in step: a task that waits on another starts the day that one ends
 * (its internal due date — start dates always pair with the internal due date, never the
 * external one, same as StartDateNotAfterDueDate). When a task's end
 * moves, each follower's start moves to match and its end date(s) shift by the same number of
 * days, keeping its duration; saving the follower cascades the same way down the chain (see
 * TaskChainObserver).
 *
 * Only applies to task types in organizations that track task start dates — with that turned
 * off, links are kept but have no effect.
 *
 * Bound as a scoped singleton so the ids it touches during one request/job can be reported back
 * (see DocumentController::updateAttributes()).
 */
class TaskChainScheduler
{
    /**
     * Chains are trees (one predecessor each, cycles are rejected on save), so this only guards
     * against corrupt data looping forever.
     */
    private const MAX_DEPTH = 500;

    private int $depth = 0;

    /** @var array<string, true> */
    private array $touched = [];

    public function isActiveFor(Document $document): bool
    {
        $project = $document->project;

        return $project !== null && $project->usesTaskStartDatesFor($document->type);
    }

    /**
     * The date a task's followers start on — null when it has no end date.
     */
    public function endDate(Document $document): ?string
    {
        return $this->dateOf($document->due_at);
    }

    /**
     * Sets a task's start to the given date (blank when its predecessor has no end), shifting its
     * end date(s) by the same number of days so its duration holds. A task with no start yet has
     * no duration to keep — its end is only pulled forward if it would otherwise come before the
     * new start. Changes the model's attributes; the caller saves.
     */
    public function applyStart(Document $task, ?string $newStart): void
    {
        $oldStart = $this->dateOf($task->start_at);

        if ($newStart === null) {
            $this->setDate($task, 'start_at', null);

            return;
        }

        $this->setDate($task, 'start_at', $newStart);

        foreach (['due_at', 'external_due_at'] as $field) {
            $end = $this->dateOf($task->getAttribute($field));
            if ($end === null) {
                continue;
            }

            if ($oldStart !== null) {
                $days = (int) Carbon::parse($oldStart)->diffInDays(Carbon::parse($newStart), false);
                $this->setDate($task, $field, Carbon::parse($end)->addDays($days)->toDateString());
            } elseif ($end < $newStart) {
                $this->setDate($task, $field, $newStart);
            }
        }
    }

    /**
     * A start date moved by hand on a task that already had both a start and an end keeps the
     * task's duration: its end date(s) move by the same number of days (the same shift a chain
     * applies — see applyStart()). Skipped when the same save also sets an end date (both edits
     * are taken as given) or when there was no start before (no duration to keep). Changes the
     * model's attributes; the caller saves.
     */
    public function keepDurationOnStartChange(Document $task): void
    {
        if (! $task->exists || ! $task->isDirty('start_at') || $task->isDirty(['due_at', 'external_due_at'])) {
            return;
        }

        $days = $this->daysBetween($this->dateOf($task->getOriginal('start_at')), $this->dateOf($task->start_at));
        if ($days === null || $days === 0) {
            return;
        }

        foreach (['due_at', 'external_due_at'] as $field) {
            $end = $this->dateOf($task->getAttribute($field));
            if ($end !== null) {
                $this->setDate($task, $field, Carbon::parse($end)->addDays($days)->toDateString());
            }
        }
    }

    /**
     * Whole days from one date to another (negative when moving earlier), or null when either is
     * missing.
     */
    public function daysBetween(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (int) Carbon::parse($from)->diffInDays(Carbon::parse($to), false);
    }

    /**
     * Re-dates every follower of a task whose end may have changed. Each follower's own save
     * re-enters here through the observer, carrying the change down the chain.
     */
    public function cascadeFrom(Document $task): void
    {
        if (! $this->isActiveFor($task) || $this->depth >= self::MAX_DEPTH) {
            return;
        }

        $end = $this->endDate($task);

        $this->depth++;

        try {
            foreach ($task->followers()->get() as $follower) {
                $this->applyStart($follower, $end);

                if ($follower->isDirty()) {
                    $follower->save();
                    $this->touched[$this->idOf($follower)] = true;
                }
            }
        } finally {
            $this->depth--;
        }
    }

    /**
     * Ids of the tasks re-dated by a cascade since this scheduler was resolved.
     *
     * @return list<string>
     */
    public function touchedIds(): array
    {
        return array_keys($this->touched);
    }

    /**
     * Every task downstream of the given one (its followers, their followers, …), from a
     * project's tasks keyed by id — what it can't wait on without creating a loop.
     *
     * @param  Collection<int, Document>  $tasks
     * @return list<string>
     */
    public function descendantIds(Collection $tasks, string $taskId): array
    {
        $followersByPredecessor = $tasks->groupBy(fn (Document $task) => (string) $task->predecessor_id);
        $found = [];
        $queue = [$taskId];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($followersByPredecessor->get($current, collect()) as $follower) {
                $id = $this->idOf($follower);
                if (! isset($found[$id]) && $id !== $taskId) {
                    $found[$id] = true;
                    $queue[] = $id;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Every task upstream of the given one — what can't be made to follow it.
     *
     * @param  Collection<int, Document>  $tasks
     * @return list<string>
     */
    public function ancestorIds(Collection $tasks, string $taskId): array
    {
        $byId = $tasks->keyBy(fn (Document $task) => $this->idOf($task));
        $found = [];
        $current = $byId->get($taskId)?->predecessor_id;

        while ($current !== null && ! isset($found[(string) $current]) && (string) $current !== $taskId) {
            $found[(string) $current] = true;
            $current = $byId->get((string) $current)?->predecessor_id;
        }

        return array_keys($found);
    }

    public function idOf(Document $document): string
    {
        $key = $document->getKey();

        return is_string($key) ? $key : '';
    }

    private function setDate(Document $task, string $field, ?string $date): void
    {
        if ($this->dateOf($task->getAttribute($field)) !== $date) {
            $task->setAttribute($field, $date);
        }
    }

    private function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
