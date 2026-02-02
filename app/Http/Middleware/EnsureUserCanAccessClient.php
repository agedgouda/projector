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

        // 3. LOGGING BLOCK - CHECK LARAVEL.LOG AFTER RUNNING
        Log::info('[AccessClient] Debugging Access:', [
            'user_id'         => $user->id,
            'user_email'      => $user->email,
            'session_org_id'  => $sessionOrgId,
            'fallback_org_id' => $fallbackOrgId,
            'current_team_id' => getPermissionsTeamId(),
            'has_super_admin' => $user->hasRole('super-admin'), // Checked against current_team_id
            'has_org_admin'   => $user->hasRole('org-admin'),   // Checked against current_team_id
            'all_roles_raw'   => \Illuminate\Support\Facades\DB::table('model_has_roles')
                                    ->where('model_id', $user->id)
                                    ->get()
                                    ->toArray(),
        ]);

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
