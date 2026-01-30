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
        $projects = Project::visibleTo($request->user())
            ->with(['type', 'client.users'])
            ->latest()
            ->get();

        // IF NO PROJECTS: Render the "Access Denied/Pending" page instead
        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $request->user(),
                'message' => 'Your account is currently awaiting assignment to a client.'
            ]);
        }

        $projectId = $request->query('project');
        $currentProject = $projectId
            ? $projects->where('id', $projectId)->first()
            : $projects->first();

        // Standard Dashboard render for users with projects
        return Inertia::render('Dashboard/Index', [
            'projects' => $projects,
            'currentProject' => $currentProject,
            'kanbanData' => (object)Document::where('project_id', $currentProject->id)
                ->with('assignee')
                ->get()
                ->groupBy('type'),
        ]);
    }
}
