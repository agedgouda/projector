<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOrganizationContext
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $organizationId = $this->resourceOrganizationId($request);

        // Visiting a project/client the user can reach makes its org the active one. This runs
        // before HandleInertiaRequests, so the org picker reflects the switch on this very page
        // rather than one navigation later (and for super-admins, whom EnsureUserCanAccessClient
        // never switches). API requests have no session or cookies to persist it in.
        if ($organizationId !== null && $request->hasSession() && $this->canAccessOrganization($user, $organizationId)) {
            $request->session()->put('active_org_id', $organizationId);
            cookie()->queue(cookie()->forever('last_org_id', $organizationId));
        }

        if ($organizationId === null) {
            $organizationId = $request->cookie('last_org_id')
                ?? $user->organizations()->value('organizations.id');
        }

        if (is_string($organizationId) && $organizationId !== '') {
            setPermissionsTeamId($organizationId);
        }

        return $next($request);
    }

    /**
     * The org that owns the {project} or {client} route parameter, if the route has one.
     */
    private function resourceOrganizationId(Request $request): ?string
    {
        $route = $request->route();

        if (! is_object($route)) {
            return null;
        }

        $projectParam = $route->parameter('project');

        if ($projectParam) {
            $projectId = $projectParam instanceof Project ? $projectParam->id : $projectParam;

            if (! is_string($projectId)) {
                return null;
            }

            $organizationId = Project::where('id', $projectId)->with('client')->first()?->client?->organization_id;

            return $organizationId !== null ? (string) $organizationId : null;
        }

        $clientParam = $route->parameter('client');

        if ($clientParam) {
            $clientId = $clientParam instanceof Client ? $clientParam->id : $clientParam;

            if (! is_string($clientId)) {
                return null;
            }

            $organizationId = Client::where('id', $clientId)->value('organization_id');

            return is_scalar($organizationId) ? (string) $organizationId : null;
        }

        return null;
    }

    private function canAccessOrganization(User $user, string $organizationId): bool
    {
        if ($user->organizations()->where('organizations.id', $organizationId)->exists()) {
            return true;
        }

        $previousTeamId = getPermissionsTeamId();

        // The super-admin role is global (team_id = null), so it's only visible from a null team.
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        $user->unsetRelation('roles');
        setPermissionsTeamId($previousTeamId);

        return $isSuperAdmin;
    }
}
