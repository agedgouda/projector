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

        $currentProject = $projects->findCurrent($request->query('project'));

        /**
         * CRITICAL STEP:
         * We compute both pipes based on the FRESHLY LOADED documents
         * before we call setRelation.
         */
        $kanbanData = $currentProject->getKanbanPipe();
        $documentation = $currentProject->getDocumentationPipe();

        // Now it's safe to overwrite the relation for the UI tree
        $currentProject->setRelation('documents', $documentation);

        return Inertia::render('Dashboard/Index', [
            'projects' => $projects,
            'currentProject' => $currentProject,
            'kanbanData' => (object)$kanbanData,
            'activeTab' => $request->query('tab', 'tasks')
        ]);
    }
}
