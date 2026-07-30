<?php

namespace App\Observers;

use App\Models\KanbanColumn;
use App\Models\Project;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ProjectObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Project $project): void
    {
        KanbanColumn::seedDefaultsFor($project);
    }
}
