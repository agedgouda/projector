<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;

class CheckOrgRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        // Capture the active org context before clearing for super-admin check
        $activeOrgId = getPermissionsTeamId()
            ?? $request->session()->get('active_org_id')
            ?? $request->cookie('last_org_id');

        // Super-admins bypass all org role checks (global role with team_id = null)
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        if (! $activeOrgId) {
            throw UnauthorizedException::forRoles($roles);
        }

        $orgRole = $user->organizations()
            ->where('organizations.id', $activeOrgId)
            ->first()
            ?->pivot
            ?->role;

        if (! in_array($orgRole, $roles)) {
            throw UnauthorizedException::forRoles($roles);
        }

        // Restore org context so downstream controllers can use getPermissionsTeamId()
        setPermissionsTeamId($activeOrgId);

        return $next($request);
    }
}
