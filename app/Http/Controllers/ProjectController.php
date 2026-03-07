<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Project::class);

        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        $user = $request->user();

        // Security: If not a Super-Admin and not assigned to any organization, deny.
        if (! $user->hasRole('super-admin') && $user->organizations()->doesntExist()) {
            abort(404);
        }

        $projects = Project::visibleTo($user, $orgId)
            ->latest()
            ->get()
            ->withSummary();

        return inertia('Projects/Index', [
            'projects' => $projects,
            'clients' => $user->newCollection([$user])->availableClients(),
            'projectTypes' => $user->hasRole('super-admin')
                ? ProjectType::all(['id', 'name'])
                : ProjectType::where('organization_id', $orgId)->get(['id', 'name']),
        ]);
    }

    public function show(Project $project, Request $request)
    {
        $user = auth()->user();

        // 1. Get projects using your custom collection
        $projects = Project::visibleTo($user)->latest()->get()->withDashboardContext();

        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $user,
                'message' => 'There are no projects available.',
            ]);
        }

        // 3. Extract clients from the user's own collection
        // (Assuming your User model uses the UserCollection)
        $clients = $user->newCollection([$user])->availableClients();

        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        return Inertia::render('Projects/Show', [
            'projects' => $projects,
            'currentProject' => $project,
            'kanbanData' => [(string) $project->id => $project->getKanbanDocuments()],
            'activeTab' => $tab,
            'clients' => $clients,
            'projectTypes' => $user->hasRole('super-admin')
                ? \App\Models\ProjectType::all(['id', 'name'])
                : \App\Models\ProjectType::where('organization_id', getPermissionsTeamId())->get(['id', 'name']),
        ])
            ->toResponse($request)
            ->withCookie(cookie()->forever('last_project_id', $project->id))
            ->withCookie(cookie()->forever('last_active_tab', $tab));
    }

    /**
     * Store a newly created project.
     * Uses ProjectRequest to handle context-switching and authorization.
     */
    public function store(ProjectRequest $request)
    {
        try {
            // Validation and Authorization already handled by ProjectRequest
            // But we'll call Gate::authorize here to ensure the standard 403 flow
            Gate::authorize('create', Project::class);

            $project = Project::create($request->validated());

            return redirect()->route('dashboard', ['project' => $project->id])
                ->with('success', 'Project created successfully.');

        } catch (\Exception $e) {
            \Log::error('[ProjectController] Store failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 300),
            ]);
            throw $e;
        }
    }

    public function update(ProjectRequest $request, Project $project)
    {
        try {
            Gate::authorize('update', $project);

            $project->update($request->validated());

            return redirect()->back()->with('success', 'Project updated successfully.');

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {

            \Log::error('[ControllerDebug] Authorization Failed', [
                'user_id' => auth()->id(),
                'project_org_id' => $project->organization_id, // Hits our new accessor
                'active_team_id' => getPermissionsTeamId(),
            ]);
            throw $e;
        }
    }

    public function destroy(Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('delete', $project);

        $project->delete();
        $message = 'Project was successfully deleted.';

        $redirectTo = request()->get('redirect_to');
        if ($redirectTo && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return redirect($redirectTo)->with('success', $message);
        }

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function updateLifecycleStep(Request $request, Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->organization_id);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'current_lifecycle_step_id' => 'nullable|integer|exists:lifecycle_steps,id',
        ]);

        $project->update($validated);

        return back();
    }

    public function storeDocument(Request $request, Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'content' => 'required|string',
        ]);

        $project->documents()->create($validated);

        return back()->with('success', 'Document added and indexed.');
    }
}
