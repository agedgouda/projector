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
        $user = $request->user();

        // 1. Get projects using your custom collection
        $projects = Project::visibleTo($user)->latest()->get()->withDashboardContext();

        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $user,
                'message' => 'Your account is currently awaiting assignment to a client.'
            ]);
        }

        // 2. Use the new collection method to resolve project
        $currentProject = $projects->resolveCurrent(
            $request->query('project') ?? $request->cookie('last_project_id')
        );

        // 3. Extract clients from the user's own collection
        // (Assuming your User model uses the UserCollection)
        $clients = $user->newCollection([$user])->availableClients();

        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        \Log::info('PRODUCTION DATA CHECK', [
            'project_id' => $currentProject->id,
            'docs_count' => count($liveDocuments),
            'sample_doc' => !empty($liveDocuments) ? [
                'id' => $liveDocuments[0]['id'],
                'parent_id' => $liveDocuments[0]['parent_id'] ?? 'NULL',
                'type' => $liveDocuments[0]['type']
            ] : 'EMPTY'
        ]);

        return Inertia::render('Dashboard/Index', [
            'projects'     => $projects,
            'currentProject' => $currentProject,
            'kanbanData'   => (object) $currentProject->getKanbanPipe(),
            'activeTab'    => $tab,
            'clients'      => $clients,
            'projectTypes' => \App\Models\ProjectType::all(['id', 'name']),
        ])
        ->toResponse($request)
        ->withCookie(cookie()->forever('last_project_id', $currentProject->id))
        ->withCookie(cookie()->forever('last_active_tab', $tab));
    }
}
