<?php

namespace App\Http\Middleware;

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

        // 1. Identify Context
        $activeOrgId = $request->session()->get('active_org_id')
            ?? $request->cookie('last_org_id')
            ?? $user->organizations->first()?->id;

        // 2. Super-admin check must use null team context (global role has team_id = null)
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        if ($user->hasRole('super-admin')) {
            setPermissionsTeamId($activeOrgId);

            return $next($request);
        }

        // 3. Restore org context and check access
        setPermissionsTeamId($activeOrgId);

        if ($this->canAccessOrg($user, $activeOrgId)) {
            return $next($request);
        }

        // 4. Active org denied — try any org where the user is an org-admin (uses loaded relation)
        $adminOrg = $user->organizations->first(fn ($org) => $org->pivot->role === 'org-admin');

        if ($adminOrg) {
            setPermissionsTeamId($adminOrg->id);

            return $next($request);
        }

        // 5. Last resort — try any org where the user has direct client access
        foreach ($user->organizations as $org) {
            if ($user->clients()->where('clients.organization_id', $org->id)->exists()) {
                setPermissionsTeamId($org->id);

                return $next($request);
            }
        }

        Log::error('[AccessClient] Access Denied for User '.$user->id);
        abort(404);
    }

    private function canAccessOrg(User $user, ?string $orgId): bool
    {
        if (! $orgId) {
            return false;
        }

        if ($user->isOrgAdmin($orgId)) {
            return true;
        }

        return $user->clients()->where('clients.organization_id', $orgId)->exists();
    }
}
