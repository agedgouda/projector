<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
        $organizations = [];

        if ($user) {
            $rawQueryOrgId = $request->query('org');
            $queryOrgId = is_string($rawQueryOrgId) ? $rawQueryOrgId : null;

            $activeOrgId = $queryOrgId
                           ?? $request->session()->get('active_org_id')
                           ?? $request->cookie('last_org_id')
                           ?? $user->organizations->first()?->id;

            // An explicit ?org= switch (e.g. the header org picker) needs to persist beyond
            // this one request — otherwise session('active_org_id'), which outranks the
            // cookie, keeps whatever org was active before and the switch appears to "undo
            // itself" the moment you navigate to a page that doesn't also pass ?org=.
            if ($queryOrgId) {
                $request->session()->put('active_org_id', $queryOrgId);
                cookie()->queue(cookie()->forever('last_org_id', $queryOrgId));
            }

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

            // Kept lean (no logo) — this loads on every request, unlike the fuller org list
            // the Organizations page itself fetches, so it stays a single cheap query.
            $organizations = Organization::accessibleBy($user)->orderBy('name')->get(['id', 'name'])->toArray();
        }

        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = is_int($impersonatorId) ? User::find($impersonatorId) : null;

        $activeOrgId = getPermissionsTeamId();
        $orgMembership = null;

        if ($user && $activeOrgId) {
            $org = Organization::find($activeOrgId);
            if ($org) {
                $limits = $org->tierLimits();
                $aiUsage = $org->currentMonthAiUsage();
                $userCount = $org->currentUserCount();
                $clientCount = $org->currentClientCount();
                $projectCount = $org->currentProjectCount();

                $orgMembership = [
                    'tier' => $org->membership_tier,
                    'tier_label' => $org->tierLabel(),
                    'uses_external_due_dates' => $org->uses_external_due_dates,
                    'limits' => $limits,
                    'usage' => [
                        'users' => $userCount,
                        'clients' => $clientCount,
                        'projects' => $projectCount,
                        'ai_docs_this_month' => $aiUsage,
                    ],
                    'at_limit' => [
                        'users' => $limits['users'] !== null && $userCount >= $limits['users'],
                        'clients' => $limits['clients'] !== null && $clientCount >= $limits['clients'],
                        'projects' => $limits['projects'] !== null && $projectCount >= $limits['projects'],
                        'ai_docs' => $limits['ai_docs_per_month'] !== null && $aiUsage >= $limits['ai_docs_per_month'],
                    ],
                ];
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
                'active_org_id' => $activeOrgId,
                'impersonating' => $impersonator ? ['id' => $impersonator->id, 'name' => $impersonator->name] : null,
            ],
            'organizations' => $organizations,
            'orgMembership' => $orgMembership,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'aiResults' => fn () => $request->session()->get('aiResults'),
                'newClientId' => $request->session()->get('newClientId'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'session' => [
                'lifetime_minutes' => Config::integer('session.lifetime'),
            ],
        ];
    }
}
