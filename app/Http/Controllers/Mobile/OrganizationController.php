<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    /**
     * Whether this user has more than one organization to choose from — a super-admin can
     * see every organization regardless of membership, so they always qualify even with no
     * explicit attachments of their own.
     */
    public static function needsToChoose(User $user): bool
    {
        return $user->hasRole('super-admin') || $user->organizations()->count() > 1;
    }

    /**
     * Pick which organization to see projects for. Reached on first launch (before any
     * choice has been made) and any time afterward via the dashboard header, for a user with
     * access to more than one organization.
     */
    public function index(Request $request): \Inertia\Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $organizations = Organization::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Mobile/Organizations/Index', [
            'organizations' => $organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
            ]),
            'currentOrganizationId' => $request->cookie('last_org_id'),
        ]);
    }

    /**
     * Save the choice as the same forever cookie the desktop org switcher uses, so it
     * survives closing the app (the Capacitor WebView persists cookies like a normal
     * browser) until the user picks a different organization here.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
        ]);

        $organizationId = Organization::accessibleBy($user)
            ->where('id', $validated['organization_id'])
            ->value('id');

        if (! is_string($organizationId)) {
            abort(404);
        }

        cookie()->queue(cookie()->forever('last_org_id', $organizationId));

        return redirect()->route('mobile.dashboard');
    }
}
