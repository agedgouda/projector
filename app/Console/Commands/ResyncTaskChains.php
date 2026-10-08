<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Tasks\TaskChainScheduler;
use Illuminate\Console\Command;

class ResyncTaskChains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:resync-task-chains {--dry-run : Report what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-date every linked task from the start of its chain down, so each follower starts the day its predecessor\'s internal due date falls (keeping its duration). Only touches organizations that track task start dates. Safe to re-run.';

    /**
     * Chains only re-date when a predecessor's due date changes, so links made under an earlier
     * rule (or while the organization had task start dates turned off) can sit out of step until
     * then. This walks each chain from its first task and applies the current rule to every
     * follower, using each task's updated dates for the ones after it. Every change is worked out
     * in memory first and saved afterward, in chain order — saving mid-walk would let
     * TaskChainObserver re-date the rest of the chain ahead of the walk, hiding those changes
     * from the report.
     */
    public function handle(TaskChainScheduler $scheduler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $checked = 0;

        Document::query()
            ->whereNull('predecessor_id')
            ->whereHas('followers')
            ->with('project.client.organization')
            ->orderBy('created_at')
            ->each(function (Document $root) use ($scheduler, $dryRun, &$changed, &$checked): void {
                if (! $scheduler->isActiveFor($root)) {
                    return;
                }

                $queue = [$root];
                $visited = [$scheduler->idOf($root) => true];
                $toSave = [];

                while ($queue !== []) {
                    $task = array_shift($queue);
                    $end = $scheduler->endDate($task);

                    foreach ($task->followers()->get() as $follower) {
                        $followerId = $scheduler->idOf($follower);
                        if (isset($visited[$followerId])) {
                            continue;
                        }
                        $visited[$followerId] = true;
                        $checked++;

                        $scheduler->applyStart($follower, $end);

                        if ($follower->isDirty()) {
                            $changed++;
                            $this->line(sprintf(
                                '%s "%s" (%s): %s',
                                $dryRun ? 'Would re-date' : 'Re-dated',
                                $follower->name,
                                $followerId,
                                collect($follower->getDirty())
                                    ->map(fn (mixed $value, string $field): string => sprintf(
                                        '%s %s → %s',
                                        $field,
                                        $this->dateLabel($follower->getOriginal($field)),
                                        $this->dateLabel($value),
                                    ))
                                    ->implode(', '),
                            ));

                            $toSave[] = $follower;
                        }

                        $queue[] = $follower;
                    }
                }

                if (! $dryRun) {
                    foreach ($toSave as $follower) {
                        $follower->save();
                    }
                }
            });

        $this->info(sprintf(
            '%d linked task(s) checked; %d %s.',
            $checked,
            $changed,
            $dryRun ? 'would be re-dated (dry run — nothing saved)' : 're-dated',
        ));

        return self::SUCCESS;
    }

    private function dateLabel(mixed $value): string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : 'none';
    }
}
