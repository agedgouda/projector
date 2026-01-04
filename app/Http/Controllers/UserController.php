<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::with('roles')->get()->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'roles' => $user->getRoleNames(),
            ]),
            'allRoles' => Role::all()->pluck('name'),
        ]);
    }

    public function updateRole(Request $request, User $user)
    {
        // Ensure the admin isn't removing their own admin role (safety first!)
        if ($user->id === auth()->id() && $request->role === 'admin' && $user->hasRole('admin')) {
            return back()->with('error', 'You cannot remove your own admin role.');
        }

        // Spatie's toggle logic
        $user->hasRole($request->role)
            ? $user->removeRole($request->role)
            : $user->assignRole($request->role);

        return back();
    }
}
