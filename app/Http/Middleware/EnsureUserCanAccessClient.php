<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnsureUserCanAccessClient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            Log::warning('[AccessClient] No user found in request.');
            abort(404);
        }

        // 2. Super-admin check must use null team context (global role has team_id = null)
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        if ($user->hasRole('super-admin')) {
            setPermissionsTeamId($this->resolveActiveOrgId($request, $user));

            return $next($request);
        }

        // If this request is for a specific project/client and the user actually belongs to
        // the org that owns it, switch them into that org — visiting a resource you have
        // access to should make it the active org, not just render underneath whichever org
        // happened to be active before.
        $resourceOrgId = $this->resolveResourceOrgId($request);

        if ($resourceOrgId && $this->canAccessOrg($user, $resourceOrgId)) {
            $this->activateOrg($request, $resourceOrgId);

            return $next($request);
        }

        // 1. Identify Context
        $activeOrgId = $this->resolveActiveOrgId($request, $user);

        // 3. Restore org context and check access
        setPermissionsTeamId($activeOrgId);

        if ($this->canAccessOrg($user, $activeOrgId)) {
            return $next($request);
        }

        // 4. Active org denied — try any org where the user is a member
        $anyOrg = $user->organizations->first();

        if ($anyOrg) {
            setPermissionsTeamId($anyOrg->id);

            return $next($request);
        }

        Log::error('[AccessClient] Access Denied for User '.$user->id);
        abort(404);
    }

    /**
     * Resolves the org that owns the {project} or {client} route parameter for this request,
     * if either is present — mirrors SetOrganizationContext's own resolution so both
     * middlewares agree on which org a given resource belongs to.
     */
    private function resolveResourceOrgId(Request $request): ?string
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        $projectParam = $route->parameter('project');

        if ($projectParam) {
            $projectId = is_object($projectParam) ? $projectParam->id : $projectParam;
            $project = Project::where('id', (string) $projectId)->with('client')->first();

            return $project?->client?->organization_id;
        }

        $clientParam = $route->parameter('client');

        if ($clientParam) {
            $clientId = is_object($clientParam) ? $clientParam->id : $clientParam;

            return Client::where('id', (string) $clientId)->value('organization_id');
        }

        return null;
    }

    private function resolveActiveOrgId(Request $request, User $user): ?string
    {
        return $request->session()->get('active_org_id')
            ?? $request->cookie('last_org_id')
            ?? $user->organizations->first()?->id;
    }

    /**
     * Makes $orgId the user's active org for this request and persists it, so both this
     * response's own shared Inertia props and future requests immediately reflect the switch.
     */
    private function activateOrg(Request $request, string $orgId): void
    {
        setPermissionsTeamId($orgId);
        $request->session()->put('active_org_id', $orgId);
        cookie()->queue(cookie()->forever('last_org_id', $orgId));
    }

    private function canAccessOrg(User $user, ?string $orgId): bool
    {
        if (! $orgId) {
            return false;
        }

        return $user->organizations()->where('organizations.id', $orgId)->exists();
    }
}
