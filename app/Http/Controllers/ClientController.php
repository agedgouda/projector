<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Organization;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Resolve Org Context (same logic as your OrganizationController)
        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        // Ensure the user actually has access to this org (or is super-admin)
        /** @var Organization $organization */
        $organization = $user->hasRole('super-admin')
            ? Organization::findOrFail($orgId)
            : $user->organizations()->findOrFail($orgId);

        // 2. Set Spatie Team Context
        setPermissionsTeamId($organization->id);

        // 3. Fetch clients strictly for this organization
        $clients = Client::where('organization_id', $organization->id)
            ->latest()
            ->with(['projects.type', 'projects.media', 'media'])
            ->get()
            ->map(fn (Client $c) => array_merge($c->toArray(), [
                'logo_url' => $c->logo_url,
                'projects' => $c->projects->map(fn ($p) => array_merge($p->toArray(), ['logo_url' => $p->logo_url]))->all(),
            ]));

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

        $clients = Client::where('organization_id', $client->organization_id)
            ->latest()
            ->with(['projects.type', 'media'])
            ->get()
            ->map(fn (Client $c) => array_merge($c->toArray(), ['logo_url' => $c->logo_url]));

        return inertia('Clients/Index', [
            'clients' => $clients,
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
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $orgId = $request->cookie('last_org_id') ?? getPermissionsTeamId();
        setPermissionsTeamId($orgId);

        Gate::authorize('create', Client::class);

        $org = \App\Models\Organization::find($orgId);
        if ($org && ($block = \App\Services\MembershipGuard::check($org, 'clients'))) {
            return $block;
        }

        $client = Client::create(array_merge(
            $request->validated(),
            ['organization_id' => $orgId]
        ));

        if ($request->hasFile('logo')) {
            $client->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return back()->with('success', 'Client created successfully.')->with('newClientId', $client->id);
    }

    /**
     * Update the specified client.
     */
    public function update(ClientRequest $request, Client $client)
    {
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('update', $client);

        $validated = $request->validated();

        $client->update($validated);

        if (($validated['inactive'] ?? false) === true) {
            $client->projects()->update(['inactive' => true]);
        }

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
