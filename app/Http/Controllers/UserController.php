<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

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
            'allRoles' => Role::where('team_id', $currentOrgId)->orWhereNull('team_id')->pluck('name'),
        ]);
    }


    /**
     * Update user permissions and client assignments.
     */
    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'is_admin' => ['required', 'boolean'],
        ]);

        // 1. Lock Spatie to the organization context provided by the UI
        setPermissionsTeamId($validated['organization_id']);

        // 2. Prevent modifying Super Admins to avoid accidental lockouts
        if ($user->hasRole('super-admin')) {
            return back()->with('error', 'Super Admin permissions cannot be modified here.');
        }

        // 3. Handle Admin status
        // If true, ensure they have the role; if false, ensure it's removed.
        $validated['is_admin']
            ? $user->assignRole('org-admin')
            : $user->removeRole('org-admin');

        return back()->with('success', 'Permissions updated.');
    }
}
