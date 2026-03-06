<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    /** @var list<string> */
    protected const ASSIGNABLE_ROLES = ['org-admin', 'project-lead', 'team-member'];

    /**
     * Display a listing of users within the current organization.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);

        $auth = auth()->user();
        $currentOrgId = getPermissionsTeamId();

        $users = User::query()
            ->with(['organizations'])
            ->when(! $auth->hasRole('super-admin'), function ($q) use ($currentOrgId) {
                return $q->whereHas('organizations', fn ($sq) => $sq->where('organizations.id', $currentOrgId));
            })
            ->get();

        return inertia('Users/Index', [
            'users' => $users->forInertia(),
            'allRoles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    /**
     * Promote a user to super-admin, removing them from all organizations. Super-admins only.
     */
    public function promote(User $user): \Illuminate\Http\RedirectResponse
    {
        setPermissionsTeamId(null);

        if ($user->hasRole('super-admin')) {
            return back()->with('error', 'User is already a super-admin.');
        }

        $user->organizations()->detach();

        DB::table('model_has_roles')->where('model_id', $user->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->assignRole('super-admin');

        return back()->with('success', "{$user->name} has been promoted to super-admin.");
    }

    /**
     * Update user permissions and client assignments.
     */
    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['nullable', 'string', 'in:'.implode(',', self::ASSIGNABLE_ROLES)],
        ]);

        setPermissionsTeamId(null);

        if ($user->hasRole('super-admin')) {
            return back()->with('error', 'Super Admin permissions cannot be modified here.');
        }

        if ($validated['role']) {
            $user->organizations()->updateExistingPivot($validated['organization_id'], [
                'role' => $validated['role'],
            ]);
        } else {
            $user->organizations()->detach($validated['organization_id']);
        }

        return back()->with('success', 'Role updated.');
    }
}
