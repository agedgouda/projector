<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationLogoController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $organization);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $organization->addMediaFromRequest('logo')->toMediaCollection('logo');

        return back();
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $organization);

        $organization->clearMediaCollection('logo');

        return back();
    }
}
