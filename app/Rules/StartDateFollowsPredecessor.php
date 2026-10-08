<?php

namespace App\Rules;

use App\Models\Document;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A task that waits on another starts the day that one ends, so its start date can't be set
 * directly — only by changing (or removing) what it waits on. Implicit so clearing the date is
 * caught too; skipped when the request doesn't touch start_at.
 */
class StartDateFollowsPredecessor implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

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
        if (! array_key_exists($attribute, $this->data) || $this->project === null || $this->type === null) {
            return;
        }

        $predecessorId = array_key_exists('predecessor_id', $this->data)
            ? $this->data['predecessor_id']
            : $this->document?->predecessor_id;

        if (! is_string($predecessorId) || $predecessorId === '' || ! $this->project->usesTaskStartDatesFor($this->type)) {
            return;
        }

        $requested = is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
        $current = is_string($this->document?->start_at) && $this->document->start_at !== '' ? substr($this->document->start_at, 0, 10) : null;

        if ($requested !== $current) {
            $name = Document::query()->whereKey($predecessorId)->value('name');
            $name = is_string($name) ? $name : 'the task it waits on';
            $fail("This task starts when \"{$name}\" ends, so its start date can't be changed directly.");
        }
    }
}
