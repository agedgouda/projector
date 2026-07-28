<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * The mobile app's home screen — the projects the user can access, same underlying
     * scope as the web dashboard/project list.
     */
    public function index(Request $request): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        // Nothing chosen yet (first launch, or a cleared cookie) and there's more than one
        // organization to pick from — send them to the picker instead of silently landing on
        // whichever organization the fallback in SetOrganizationContext happens to pick.
        if (! $request->cookie('last_org_id') && OrganizationController::needsToChoose($user)) {
            return redirect()->route('mobile.organizations.index');
        }

        $rawOrgId = getPermissionsTeamId();
        $orgId = is_int($rawOrgId) || is_string($rawOrgId) ? (string) $rawOrgId : null;

        $projects = Project::visibleTo($user, $orgId)
            ->whereHas('client', fn ($q) => $q->where('inactive', false))
            ->with('client:id,company_name')
            ->latest()
            ->get(['id', 'name', 'client_id']);

        return Inertia::render('Mobile/Dashboard', [
            'organizationName' => $orgId ? Organization::find($orgId)?->name : null,
            'canSwitchOrganization' => OrganizationController::needsToChoose($user),
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client?->company_name,
            ]),
        ]);
    }
}
