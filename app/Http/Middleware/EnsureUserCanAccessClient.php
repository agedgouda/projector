<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnsureUserCanAccessClient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            Log::warning('[AccessClient] No user found in request.');
            abort(404);
        }

        // 1. Identify Context
        $sessionOrgId = $request->session()->get('active_org_id');
        $fallbackOrgId = $user->organizations->first()?->id;
        $activeOrgId = $sessionOrgId ?? $fallbackOrgId;

        // 2. Set Context and Clear Cache
        setPermissionsTeamId($activeOrgId);
        $user->unsetRelation('roles');

        // 4. Permission Logic
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        if ($user->hasRole('org-admin')) {
            return $next($request);
        }

        if ($activeOrgId) {
            $hasClientAccess = $user->clients()
                ->where('clients.organization_id', $activeOrgId)
                ->exists();

            if ($hasClientAccess) {
                return $next($request);
            }
        }

        Log::error('[AccessClient] Access Denied for User ' . $user->id);
        abort(404);
    }
}
