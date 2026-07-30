<?php

namespace App\Rules;

use App\Models\KanbanColumn;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidKanbanColumn implements ValidationRule
{
    public function __construct(private readonly ?string $projectId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = KanbanColumn::query()
            ->where('project_id', $this->projectId)
            ->where('key', $value)
            ->exists();

        if (! $exists) {
            $fail("The selected {$attribute} is not a valid column for this project.");
        }
    }
}
