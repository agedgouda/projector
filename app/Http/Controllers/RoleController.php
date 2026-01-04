<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::with('permissions')->get(),
            // We'll send permissions too, in case you want to link them later
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
