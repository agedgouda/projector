<?php

namespace App\Rules;

use App\Models\Document;
use App\Models\Project;
use App\Services\Tasks\TaskChainScheduler;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Keeps a task's start date on or before its internal due date — start dates pair with the
 * internal due date (as task chains do, see TaskChainScheduler), never the external one.
 * Values missing from the request come from the stored document, so editing either end alone
 * is still checked against the other. Only applies to task types in organizations that track
 * task start dates; otherwise a hidden start date could block an unrelated due-date edit.
 */
class StartDateNotAfterDueDate implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly ?Project $project,
        private readonly ?string $type,
        private readonly ?Document $document = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->project === null || $this->type === null || ! $this->project->usesTaskStartDatesFor($this->type)) {
            return;
        }

        $start = $this->dateFor('start_at');
        $due = $this->dateFor('due_at');

        // Moving only the start shifts the end by the same days (TaskChainScheduler::
        // keepDurationOnStartChange()), so check against where the end will land.
        if (array_key_exists('start_at', $this->data) && ! array_key_exists('due_at', $this->data) && ! array_key_exists('external_due_at', $this->data) && $due !== null) {
            $days = app(TaskChainScheduler::class)->daysBetween($this->storedDate('start_at'), $start);
            if ($days !== null) {
                $due = Carbon::parse($due)->addDays($days)->toDateString();
            }
        }

        if ($start === null || $due === null || $start <= $due) {
            return;
        }

        $fail($attribute === 'start_at'
            ? 'The start date cannot be after the due date.'
            : 'The due date cannot be before the start date.');
    }

    private function storedDate(string $field): ?string
    {
        $raw = $this->document?->getAttribute($field);

        return is_string($raw) && $raw !== '' ? substr($raw, 0, 10) : null;
    }

    private function dateFor(string $field): ?string
    {
        $raw = array_key_exists($field, $this->data) ? $this->data[$field] : $this->document?->getAttribute($field);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
