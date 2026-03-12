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
        $roles = [];

        if ($user) {
            $activeOrgId = $request->query('org')
                           ?? $request->session()->get('active_org_id')
                           ?? $request->cookie('last_org_id')
                           ?? $user->organizations->first()?->id;

            setPermissionsTeamId($activeOrgId);

            // Check super-admin against global (null) context since the role has team_id = null
            setPermissionsTeamId(null);
            $user->unsetRelation('roles');
            $isSuperAdmin = $user->hasRole('super-admin');

            // Restore org context
            setPermissionsTeamId($activeOrgId);

            if ($isSuperAdmin) {
                $roles = ['super-admin'];
            } else {
                $orgRole = $user->organizations->firstWhere('id', $activeOrgId)?->pivot?->role;

                if ($orgRole) {
                    $roles = [$orgRole];
                }
            }
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
                    'roles' => $roles,
                    'organizations' => $user->organizations->pluck('id')->map(fn ($id) => (string) $id),
                    'clients' => $user->clients->pluck('id')->map(fn ($id) => (string) $id),
                ] : null,
                'active_org_id' => getPermissionsTeamId(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'aiResults' => fn () => $request->session()->get('aiResults'),
                'newClientId' => $request->session()->get('newClientId'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
