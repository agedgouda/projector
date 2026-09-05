<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SlackChannelBinding;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationSlackChannelsController extends Controller
{
    /**
     * Create or repoint a channel's binding. updateOrCreate rather than create, so re-submitting
     * for a channel that's already bound just repoints it at the newly chosen project instead of
     * erroring on the (slack_workspace_id, channel_id) unique constraint.
     */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $workspace = $organization->slackWorkspace;

        if ($workspace === null) {
            abort(404);
        }

        $validated = $request->validate([
            'channel_id' => 'required|string',
            'channel_name' => 'required|string',
            'project_id' => 'required|uuid',
        ]);

        /** @var User $user */
        $user = $request->user();

        $project = Project::visibleTo($user, $organization->id)
            ->whereKey($validated['project_id'])
            ->firstOrFail();

        SlackChannelBinding::updateOrCreate(
            ['slack_workspace_id' => $workspace->id, 'channel_id' => $validated['channel_id']],
            ['channel_name' => $validated['channel_name'], 'project_id' => $project->id]
        );

        return to_route('organizations.index', ['org' => $organization->id])->with('status', 'slack-channel-bound');
    }

    public function destroy(Organization $organization, SlackChannelBinding $binding): RedirectResponse
    {
        Gate::authorize('update', $organization);

        abort_unless($binding->slackWorkspace->organization_id === $organization->id, 404);

        $binding->delete();

        return to_route('organizations.index', ['org' => $organization->id])->with('status', 'slack-channel-unbound');
    }
}
