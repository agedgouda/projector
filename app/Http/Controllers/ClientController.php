<?php

namespace App\Http\Controllers;

use App\Models\{Client, ProjectType};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Removed 'users' from with() since we aren't doing assignments now
        $clients = Client::visibleTo($user)
            ->latest()
            ->with(['projects.type'])
            ->get();

        return inertia('Clients/Index', [
            'clients' => $clients,
            'projects' => [],
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function show(Client $client)
    {
        Gate::authorize('view', $client);

        return inertia('Clients/Index', [
            'clients' => Client::visibleTo(auth()->user())->latest()->get(),
            'projects' => $client->projects()->with('type')->get(),
            'activeClientId' => $client->id,
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        Gate::authorize('update', $client);

        $client->update($request->validate([
            'company_name'  => 'required|string|max:255',
            'contact_name'  => 'required|string|max:255',
            'contact_phone' => 'required|string|max:20',
        ]));

        return back()->with('success', 'Client updated.');
    }

    public function destroy(Client $client)
    {
        Gate::authorize('delete', $client);

        $client->delete();
        return back()->with('success', 'Client deleted.');
    }
}
