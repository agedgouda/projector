<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    /**
     * Display a listing of users within the current organization.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);

        $auth = auth()->user();
        $currentOrgId = getPermissionsTeamId();

        $users = User::query()
            ->with(['organizations']) // Removed 'clients'
            ->when(! $auth->hasRole('super-admin'), function ($q) use ($currentOrgId) {
                return $q->whereHas('organizations', fn ($sq) => $sq->where('organizations.id', $currentOrgId));
            })
            ->get();

        return inertia('Users/Index', [
            'users' => $users->forInertia(),
            'allRoles' => Role::whereNull('team_id')
                ->where('name', '!=', 'super-admin')
                ->pluck('name'),
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

        $assignableRoles = Role::whereNull('team_id')
            ->where('name', '!=', 'super-admin')
            ->pluck('name')
            ->toArray();

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['nullable', 'string', 'in:'.implode(',', $assignableRoles)],
        ]);

        // 1. Lock Spatie to the organization context provided by the UI
        setPermissionsTeamId($validated['organization_id']);

        // 2. Prevent modifying Super Admins to avoid accidental lockouts
        if ($user->hasRole('super-admin')) {
            return back()->with('error', 'Super Admin permissions cannot be modified here.');
        }

        // 3. Remove all current org-scoped roles, then assign the selected one
        $user->syncRoles($validated['role'] ? [$validated['role']] : []);

        return back()->with('success', 'Role updated.');
    }
}
