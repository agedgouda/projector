<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index()
    {
        return Inertia::render('Clients/Index', [
            'clients' => Client::latest()->get(),
            'projects' => [], // Empty on initial load
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
            // Load projects specifically for this client
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
