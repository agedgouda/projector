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

        $projectId = $request->query('project') ?? $request->cookie('last_project_id');
        $currentProject = $projects->findCurrent($projectId);

        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        // Store these in the request attributes so the Middleware can grab them
        $request->attributes->set('currentProject', $currentProject);
        $request->attributes->set('activeTab', $tab);

        $kanbanData = $currentProject->getKanbanPipe();
        $sortedDocs = $currentProject->documents->sortBy('type')->values();
        $currentProject->setRelation('documents', $sortedDocs);

        return Inertia::render('Dashboard/Index', [
            'projects' => $projects,
            'currentProject' => $currentProject,
            'kanbanData' => (object)$kanbanData,
            'activeTab' => $tab
        ]);
    }
}
