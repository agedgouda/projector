<?php

namespace App\Http\Controllers;

use App\Models\{Client, ProjectType, Organization};
use Illuminate\Http\Request;
use App\Http\Requests\ClientRequest;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Resolve Org Context (same logic as your OrganizationController)
        $orgId = $request->query('org') ?? $request->cookie('last_org_id');

        // Ensure the user actually has access to this org (or is super-admin)
        $organization = $user->hasRole('super-admin')
            ? Organization::findOrFail($orgId)
            : $user->organizations()->findOrFail($orgId);

        // 2. Set Spatie Team Context
        setPermissionsTeamId($organization->id);

        // 3. Fetch clients strictly for this organization
        $clients = Client::where('organization_id', $organization->id)
            ->latest()
            ->with(['projects.type'])
            ->get();

        return inertia('Clients/Index', [
            'clients' => $clients,
            'projects' => [],
            'projectTypes' => ProjectType::all(),
            'activeOrg' => $organization,
        ]);
    }

    public function show(Request $request, Client $client)
    {
        // Set context before authorizing
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('view', $client);

        return inertia('Clients/Index', [
            'clients' => Client::where('organization_id', $client->organization_id)->latest()->get(),
            'projects' => $client->projects()->with('type')->get(),
            'activeClientId' => $client->id,
            'projectTypes' => ProjectType::all(),
        ]);
    }

    /**
     * Store a newly created client.
     */
    public function store(ClientRequest $request)
    {
        $orgId = $request->cookie('last_org_id');
        setPermissionsTeamId($orgId);

        Gate::authorize('create', Client::class);

        Client::create(array_merge(
            $request->validated(),
            ['organization_id' => $orgId]
        ));

        return back()->with('success', 'Client created successfully.');
    }

    /**
     * Update the specified client.
     */
    public function update(ClientRequest $request, Client $client)
    {
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('update', $client);

        $client->update($request->validated());

        return back()->with('success', 'Client updated.');
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Client $client)
    {
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('delete', $client);

        $client->delete();
        return back()->with('success', 'Client deleted.');
    }
}
