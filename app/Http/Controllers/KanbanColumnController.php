<?php

namespace App\Http\Controllers;

use App\Models\KanbanColumn;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KanbanColumnController extends Controller
{
    /**
     * Board colors are rotated by column count, so cards get a distinct look without the
     * user having to pick one.
     *
     * @var array<int, string>
     */
    private const COLOR_PALETTE = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];

    public function store(Request $request, Project $project)
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);

        $nextOrder = ($project->kanbanColumns()->max('order') ?? 0) + 1;

        $project->kanbanColumns()->create([
            'key' => $this->uniqueKeyFor($project, $validated['label']),
            'label' => $validated['label'],
            'color' => self::COLOR_PALETTE[$project->kanbanColumns()->count() % count(self::COLOR_PALETTE)],
            'order' => $nextOrder,
        ]);

        return back()->with('success', 'Column added.');
    }

    public function update(Request $request, Project $project, KanbanColumn $kanbanColumn)
    {
        Gate::authorize('update', $project);
        abort_if($kanbanColumn->project_id !== $project->id, 404);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);

        // The key is never touched by a rename — it's what task_status/status values
        // reference, so changing it would orphan every task/document already in this column.
        $kanbanColumn->update(['label' => $validated['label']]);

        return back()->with('success', 'Column renamed.');
    }

    public function destroy(Project $project, KanbanColumn $kanbanColumn)
    {
        Gate::authorize('update', $project);
        abort_if($kanbanColumn->project_id !== $project->id, 404);

        $hasDocuments = $project->documents()->where('task_status', $kanbanColumn->key)->exists();
        $hasTasks = $project->tasks()->where('status', $kanbanColumn->key)->exists();

        if ($hasDocuments || $hasTasks) {
            throw ValidationException::withMessages([
                'kanban_column' => 'This column still has tasks in it. Move or clear them before deleting the column.',
            ]);
        }

        $kanbanColumn->delete();

        return back()->with('success', 'Column deleted.');
    }

    private function uniqueKeyFor(Project $project, string $label): string
    {
        $baseKey = Str::slug($label, '_');
        $key = $baseKey;
        $suffix = 2;

        while ($project->kanbanColumns()->where('key', $key)->exists()) {
            $key = "{$baseKey}_{$suffix}";
            $suffix++;
        }

        return $key;
    }
}
