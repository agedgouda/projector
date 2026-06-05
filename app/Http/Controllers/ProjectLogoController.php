<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectLogoController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->client->organization_id);
        Gate::authorize('update', $project);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $project->addMediaFromRequest('logo')->toMediaCollection('logo');

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->client->organization_id);
        Gate::authorize('update', $project);

        $project->clearMediaCollection('logo');

        return back();
    }
}
