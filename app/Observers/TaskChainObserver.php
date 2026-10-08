<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\Tasks\TaskChainScheduler;

/**
 * Applies TaskChainScheduler on every save, whatever made it (the board, the sheet, an import,
 * Slack, the mobile API) — so a linked task's dates can't drift from its predecessor's, and a
 * task whose start is moved keeps its duration. Kept separate from DocumentObserver, which
 * handles its events after commit; these changes belong in the same save.
 */
class TaskChainObserver
{
    public function __construct(private readonly TaskChainScheduler $scheduler) {}

    public function saving(Document $document): void
    {
        // Links only hold within one project, so a task moved to another board leaves its
        // chain (its followers are unlinked in updated() below).
        if ($document->exists && $document->isDirty('project_id')) {
            $document->predecessor_id = null;

            return;
        }

        if ($document->isDirty('predecessor_id') && $document->predecessor_id !== null && $this->scheduler->isActiveFor($document)) {
            $predecessor = Document::query()->find($document->predecessor_id);

            $this->scheduler->applyStart($document, $predecessor ? $this->scheduler->endDate($predecessor) : null);
        }

        if ($document->isDirty('start_at') && $this->scheduler->isActiveFor($document)) {
            $this->scheduler->keepDurationOnStartChange($document);
        }
    }

    public function updated(Document $document): void
    {
        if ($document->wasChanged('project_id')) {
            $document->followers()->update(['predecessor_id' => null]);

            return;
        }

        if ($document->wasChanged('due_at')) {
            $this->scheduler->cascadeFrom($document);
        }
    }
}
