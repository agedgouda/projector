<?php

namespace App\Rules;

use App\Models\Document;
use App\Models\Project;
use App\Services\Tasks\TaskChainScheduler;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A task can only wait on another task in the same project, only while the organization tracks
 * task start dates, and never on itself or anything downstream of it (which would loop).
 */
class ValidPredecessor implements ValidationRule
{
    public function __construct(
        private readonly ?Project $project,
        private readonly ?string $type,
        private readonly ?Document $document = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->project === null || $this->type === null || ! $this->project->usesTaskStartDatesFor($this->type)) {
            $fail('Linking tasks isn\'t turned on for this organization.');

            return;
        }

        $predecessor = is_string($value) ? Document::query()->find($value) : null;

        if ($predecessor === null || $predecessor->project_id !== $this->project->id || ! $this->project->usesTaskStartDatesFor($predecessor->type)) {
            $fail('A task can only wait on another task in the same project.');

            return;
        }

        if ($this->document === null) {
            return;
        }

        $scheduler = app(TaskChainScheduler::class);
        $documentId = $scheduler->idOf($this->document);
        $predecessorId = $scheduler->idOf($predecessor);
        $tasks = $this->project->documents()->get(['id', 'predecessor_id']);

        if ($predecessorId === $documentId || in_array($predecessorId, $scheduler->descendantIds($tasks, $documentId), true)) {
            $fail("A task can't wait on itself or on a task that waits on it.");
        }
    }
}
