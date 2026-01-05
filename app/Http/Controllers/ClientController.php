<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Use the scope to filter
        $clients = Client::visibleTo($user)
            ->latest()
            ->with(['projects.type','users'])
            ->get();

        // If a non-admin has 0 clients, they shouldn't be here
        if (!$user->hasRole('admin') && $clients->isEmpty()) {
            abort(404);
        }

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'projects' => [],
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name'  => 'required|string|max:255',
            'contact_name'  => 'required|string|max:255',
            'contact_phone' => 'required|string|max:20',
        ]);

        Client::create($validated);

        return redirect()->back()->with('success', 'Client created.');
    }

    public function show(Client $client)
    {
        $client->load('projects.type');
        return Inertia::render('Clients/Index', [
            'clients' => Client::latest()->get(),
            'projects' => Project::where('client_id', $client->id)->with('type')->get(),
            'activeClientId' => $client->id,
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'company_name'  => 'required|string|max:255',
            'contact_name'  => 'required|string|max:255',
            'contact_phone' => 'required|string|max:20',
        ]);

        $client->update($validated);

        return redirect()->back();
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return redirect()->back();
    }
}
