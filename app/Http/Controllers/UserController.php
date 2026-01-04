<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Client;
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
                'clients' => $user->clients->pluck('id')->map(fn($id) => (string) $id),
            ]),
            'allRoles' => Role::all()->pluck('name'),
            'allClients' => Client::select('id', 'company_name')->get()->map(fn($client) => [
                'id' => (string) $client->id,
                'company_name' => $client->company_name,
            ]),
        ]);
    }

    public function update(Request $request, User $user)
    {
        // Handle Role Toggle (from your existing logic)
        if ($request->has('role')) {
            $user->hasRole($request->role)
                ? $user->removeRole($request->role)
                : $user->assignRole($request->role);
        }

        // Handle Client Syncing
        if ($request->has('client_ids')) {
            $user->clients()->sync($request->client_ids);
        }

        return back();
    }
}
