<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{

    public function index()
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::with(['permissions', 'users']) // Added users for the reveal
                ->withCount('users')
                ->get(),
            'permissions' => Permission::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name|max:255',
        ]);

        Role::create(['name' => $validated['name']]);

        return back()->with('success', 'Role created successfully.');
    }

   public function edit(Role $role)
    {
        return Inertia::render('Roles/Edit', [
            'role' => $role->load('permissions'),
            'allPermissions' => \Spatie\Permission\Models\Permission::all(),
        ]);
    }

    // Update the role name or permissions
    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
        ]);

        $role->update(['name' => $validated['name']]);

        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    public function unassignUser(Role $role, User $user): RedirectResponse
    {
        // 1. Security: Prevent current user from removing their own admin role
        if ($role->name === 'admin' && $user->id === auth()->id()) {
            return back()->with('error', 'You cannot remove the admin role from yourself.');
        }

        // 2. Detach the role from the user
        // Spatie handles the pivot table logic automatically
        $user->removeRole($role);

        return back()->with('success', "User {$user->name} unassigned from {$role->name}.");
    }

    public function destroy(Role $role)
    {
        // Prevent deleting the core admin role
        if ($role->name === 'admin') {
            return back()->with('error', 'The admin role cannot be deleted.');
        }

        $role->delete();
        return back();
    }
}
