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

        // 4. Active org denied — try any org where the user is a member
        $anyOrg = $user->organizations->first();

        if ($anyOrg) {
            setPermissionsTeamId($anyOrg->id);

            return $next($request);
        }

        Log::error('[AccessClient] Access Denied for User '.$user->id);
        abort(404);
    }

    private function canAccessOrg(User $user, ?string $orgId): bool
    {
        if (! $orgId) {
            return false;
        }

        return $user->organizations()->where('organizations.id', $orgId)->exists();
    }
}
