<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientLogoController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('update', $client);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $client->addMediaFromRequest('logo')->toMediaCollection('logo');

        return back();
    }

    public function destroy(Client $client): RedirectResponse
    {
        setPermissionsTeamId($client->organization_id);
        Gate::authorize('update', $client);

        $client->clearMediaCollection('logo');

        return back();
    }
}
