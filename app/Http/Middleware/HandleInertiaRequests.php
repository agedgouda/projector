<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();
        $isSuperAdmin = false;

        if ($user) {
            /**
             * 1. Determine the Organization Context.
             * We prioritize the session (for the switcher) or fallback to their first org.
             * Super-admins can operate with a null context, but if they are "impersonating"
             * an org or the user is a local admin, we set the UUID.
             */
            $activeOrgId = $request->session()->get('active_org_id')
                           ?? $user->organizations->first()?->id;

            // 2. Set the Spatie Team ID globally for this request
            setPermissionsTeamId($activeOrgId);

            /**
             * 3. THE FIX: Clear Model Caches.
             * Spatie caches roles on the User model instance. If a check was performed
             * before the Team ID was set (or with a different ID), the roles will
             * come back empty. Unsetting these forces a fresh, context-aware query.
             */
            $user->unsetRelation('roles')->unsetRelation('permissions');

            // Check super-admin status against the global (null) team context, since
            // the super-admin role is stored with team_id = null and won't be found
            // when an org team context is active.
            setPermissionsTeamId(null);
            $isSuperAdmin = $user->hasRole('super-admin');
            $user->unsetRelation('roles')->unsetRelation('permissions');

            // Restore the org context for the rest of the request
            setPermissionsTeamId($activeOrgId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super' => $isSuperAdmin,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'clients' => $user->clients->pluck('id')->map(fn ($id) => (string) $id),
                ] : null,
                'active_org_id' => getPermissionsTeamId(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'aiResults' => fn () => $request->session()->get('aiResults'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
