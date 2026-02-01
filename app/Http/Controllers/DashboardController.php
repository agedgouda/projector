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
            ->latest()
            ->get()
            ->withDashboardContext();

        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $request->user(),
                'message' => 'Your account is currently awaiting assignment to a client.'
            ]);
        }

        // Best way: Resolve ID from URL, then Cookie, then fallback to first project
        $projectId = $request->query('project') ?? $request->cookie('last_project_id');

        $currentProject = $projects->findCurrent($projectId);
        $kanbanData = $currentProject->getKanbanPipe();

        $sortedDocs = $currentProject->documents->sortBy('type')->values();
        $currentProject->setRelation('documents', $sortedDocs);

        // Best way: Resolve Tab from URL, then Cookie, then fallback to 'tasks'
        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        return Inertia::render('Dashboard/Index', [
    'projects' => $projects,
    'currentProject' => $currentProject,
    'kanbanData' => (object)$kanbanData,
    'activeTab' => $tab
])
->toResponse($request)
->withCookie(cookie()->forever('last_project_id', $currentProject->id))
->withCookie(cookie()->forever('last_active_tab', $tab));
    }
}
