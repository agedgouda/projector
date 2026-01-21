<?php

namespace App\Http\Controllers;

use App\Models\{User, Client};
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index()
    {
        // 1. Security check
        Gate::authorize('viewAny', User::class);

        return inertia('Users/Index', [
            'users' => User::with(['roles', 'clients'])->get()->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'roles' => $user->getRoleNames(),
                'clients' => $user->clients->pluck('id')->map(fn($id) => (string) $id),
            ]),
            'allRoles' => Role::pluck('name'),
            'allClients' => Client::select('id', 'company_name')->get()->map(fn($c) => [
                'id' => (string) $c->id,
                'company_name' => $c->company_name,
            ]),
        ]);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        // 2. Optimized Role Toggle
        if ($role = $request->get('role')) {
            $user->hasRole($role) ? $user->removeRole($role) : $user->assignRole($role);
        }

        // 3. Client Syncing
        if ($request->has('client_ids')) {
            $user->clients()->sync($request->client_ids);
        }

        return back()->with('success', 'Permissions updated.');
    }
}
