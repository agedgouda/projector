<?php

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;

class ProjectCollection extends Collection
{
    /**
     * For the Index/Main Dashboard list.
     * Loads just enough to show the project cards.
     */
    public function withSummary(): self
    {
        return $this->load(['client', 'type', 'documents']);
    }

    /**
     * For the "Project Show" page.
     * Loads the full tree needed for the AI transformation pipeline.
     */
    public function withFullPipeline(): self
    {
        return $this->load([
            'client.users',
            'type',
            'tasks' => fn($q) => $q->with(['assignee', 'comments.user'])->orderBy('created_at', 'asc'),
            'documents' => fn($q) => $q->with([
                'creator', 'editor', 'assignee',
                'tasks' => fn($t) => $t->with(['assignee', 'comments.user'])->orderBy('created_at', 'asc')
            ])->orderBy('created_at', 'desc')
        ]);
    }
}
