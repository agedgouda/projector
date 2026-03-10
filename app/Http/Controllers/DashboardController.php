<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Capture orgId before clearing team context for the super-admin check
        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        // Super-admin must be checked with null team context (the role has team_id = null)
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');

        if ($user->organizations->isEmpty() && ! $isSuperAdmin) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $user,
                'message' => 'You have not yet been added to an organization.',
            ]);
        }

        // Super-admins must always land on an org that has projects.
        if ($isSuperAdmin) {
            $hasProjects = $orgId && Project::whereHas('client', fn ($q) => $q->where('organization_id', $orgId))->exists();

            if (! $hasProjects) {
                $orgId = Organization::whereHas('clients.projects')->value('id');
                if ($orgId) {
                    return redirect()->route('dashboard', ['org' => $orgId])
                        ->withCookie(cookie()->forever('last_org_id', (string) $orgId));
                }
            }
        }

        setPermissionsTeamId($orgId);

        $orgRole = $orgId ? $user->roleInOrganization($orgId) : null;
        $isTeamMemberOnly = ! $isSuperAdmin && $orgRole === 'team-member';

        $projects = Project::whereHas('client', fn ($q) => $q->where('organization_id', $orgId))
            ->latest()
            ->get()
            ->withDashboardContext();

        $kanbanData = $projects->asKanbanData();

        if ($isTeamMemberOnly) {
            $kanbanData = array_map(
                fn ($docs) => array_values(array_filter($docs, fn ($doc) => ($doc['assignee_id'] ?? null) == $user->id)),
                $kanbanData
            );
        }

        $clients = $user->newCollection([$user])->availableClients();
        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        $response = Inertia::render('Dashboard/Index', [
            'projects' => $projects,
            'kanbanData' => $kanbanData,
            'activeTab' => $tab,
            'clients' => $clients,
            'currentOrganization' => $orgId ? Organization::find($orgId, ['id', 'name']) : null,
            'projectTypes' => $isSuperAdmin
                ? ProjectType::all(['id', 'name'])
                : ProjectType::where('organization_id', $orgId)->get(['id', 'name']),
        ])->toResponse($request);

        $response = $response->withCookie(cookie()->forever('last_active_tab', $tab));

        if ($isSuperAdmin && $orgId) {
            $response = $response->withCookie(cookie()->forever('last_org_id', (string) $orgId));
        }

        return $response;
    }
}
