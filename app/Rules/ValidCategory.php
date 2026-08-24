<?php

namespace App\Rules;

use App\Models\Category;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCategory implements ValidationRule
{
    public function __construct(private readonly ?Project $project) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->project && Category::query()
            ->where('id', $value)
            ->where('project_id', $this->project->familyRoot()->id)
            ->exists();

        if (! $exists) {
            $fail("The selected {$attribute} does not belong to this project's category set.");
        }
    }
}
