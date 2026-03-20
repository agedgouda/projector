<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): \Inertia\Response
    {
        $orgId = getPermissionsTeamId();

        return Inertia::render('Roles/Index', [
            'roles' => Role::with(['permissions', 'users'])
                ->withCount('users')
                ->where('team_id', $orgId)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgId = getPermissionsTeamId();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where('team_id', $orgId),
            ],
        ]);

        Role::create([
            'name' => $validated['name'],
            'team_id' => $orgId,
        ]);

        return back()->with('success', 'Role created successfully.');
    }

    public function edit(Role $role): \Inertia\Response
    {
        abort_if($role->team_id !== getPermissionsTeamId(), 403);

        return Inertia::render('Roles/Edit', [
            'role' => $role->load('permissions'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $orgId = getPermissionsTeamId();
        abort_if($role->team_id !== $orgId, 403);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where('team_id', $orgId)->ignore($role->id),
            ],
        ]);

        $role->update(['name' => $validated['name']]);

        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    public function unassignUser(Role $role, User $user): RedirectResponse
    {
        abort_if($role->team_id !== getPermissionsTeamId(), 403);

        $user->removeRole($role);

        return back()->with('success', "User {$user->name} unassigned from {$role->name}.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->team_id !== getPermissionsTeamId(), 403);

        $role->delete();

        return back();
    }
}
