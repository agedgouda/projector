<?php

namespace App\Rules;

use App\Models\Document;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Keeps a task's start date on or before its effective due date — the external due date when
 * the organization tracks one, otherwise the internal one (same fallback as the calendar).
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

        $organization = $this->project->client?->organization;

        $start = $this->dateFor('start_at');
        $effectiveDue = ($organization?->uses_external_due_dates ? $this->dateFor('external_due_at') : null)
            ?? $this->dateFor('due_at');

        if ($start === null || $effectiveDue === null || $start <= $effectiveDue) {
            return;
        }

        $fail($attribute === 'start_at'
            ? 'The start date cannot be after the due date.'
            : 'The due date cannot be before the start date.');
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
