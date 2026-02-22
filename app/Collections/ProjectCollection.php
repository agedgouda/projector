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
            'type.lifecycleSteps',
            'tasks' => fn ($q) => $q->with(['assignee', 'comments.user'])->orderBy('created_at', 'asc'),
            'documents' => fn ($q) => $q->with([
                'creator', 'editor', 'assignee',
                'tasks' => fn ($t) => $t->with(['assignee', 'comments.user'])->orderBy('created_at', 'asc'),
            ])->orderBy('created_at', 'desc'),
        ]);
    }

    /**
     * For the Unified Dashboard.
     * Loads documents once so we can split them into Tasks and Documentation in memory.
     */
    public function withDashboardContext(): self
    {
        return $this->load([
            'type.lifecycleSteps',
            'currentLifecycleStep',
            'client.users',
            'documents' => function ($q) {
                $q->with(['assignee', 'creator'])->latest();
            },
        ]);
    }

    public function findCurrent(?string $id)
    {
        return $id ? $this->where('id', $id)->first() : $this->first();
    }

    /**
     * Intelligent current project resolver.
     * Handles the fallback to the first project if the ID is missing or invalid.
     */
    public function resolveCurrent(?string $id)
    {
        $current = $id ? $this->where('id', $id)->first() : null;

        return $current ?: $this->first();
    }
}
