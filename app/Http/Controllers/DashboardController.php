<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Document;
use App\Models\Client;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1. Get only projects the user is allowed to see (Policy-aligned)
        $projects = Project::visibleTo($request->user())
            ->with('type')
            ->latest()
            ->get();

        \Log::info("here");

        // 2. Determine active project context
        // We find the project within the authorized $projects collection
        // to prevent ID-guessing/unauthorized access.
        $projectId = $request->query('project');

        $currentProject = $projectId
            ? $projects->where('id', $projectId)->first()
            : $projects->first();

        // 3. Fetch Kanban data only if a project exists and is accessible
        $kanbanData = [];
        if ($currentProject) {
            $kanbanData = Document::where('project_id', $currentProject->id)
                ->with('assignee')
                ->get()
                ->groupBy('type');
        }

        // 4. Match the prop contract exactly to Dashboard.vue
        return Inertia::render('Dashboard', [
            'projects' => $projects,
            'currentProject' => $currentProject,
            'kanbanData' => $kanbanData,
            // Including shared state usually expected by AppLayout
            'auth' => [
                'user' => $request->user(),
            ]
        ]);
    }
}
