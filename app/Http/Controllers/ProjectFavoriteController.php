<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectFavoriteController extends Controller
{
    public function store(Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->organization_id);
        Gate::authorize('view', $project);

        $project->favoritedBy()->syncWithoutDetaching([auth()->id()]);

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->organization_id);
        Gate::authorize('view', $project);

        $project->favoritedBy()->detach(auth()->id());

        return back();
    }
}
