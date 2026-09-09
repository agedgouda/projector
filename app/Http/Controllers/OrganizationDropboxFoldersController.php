<?php

namespace App\Http\Controllers;

use App\Models\DropboxFolderBinding;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Dropbox\DropboxApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors OrganizationSlackChannelsController, one folder standing in for one channel — with one
 * real difference: Slack's channel picker lets the frontend pick from a real conversations.list,
 * but there's no equivalently simple "list every folder" Dropbox call to build a picker from, so
 * the admin types a path directly and store() resolves it to Dropbox's own folder id itself
 * (DropboxApiClient::resolveFolder()) rather than the frontend needing to already know it.
 */
class OrganizationDropboxFoldersController extends Controller
{
    /**
     * Create or repoint a folder's binding. updateOrCreate rather than create, so re-submitting
     * for a folder that's already bound just repoints it at the newly chosen project instead of
     * erroring on the (dropbox_workspace_id, folder_id) unique constraint.
     */
    public function store(Request $request, Organization $organization, DropboxApiClient $client): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $workspace = $organization->dropboxWorkspace;

        if ($workspace === null) {
            abort(404);
        }

        $validated = $request->validate([
            'folder_path' => 'required|string',
            'project_id' => 'required|uuid',
        ]);

        /** @var User $user */
        $user = $request->user();

        $project = Project::visibleTo($user, $organization->id)
            ->whereKey($validated['project_id'])
            ->firstOrFail();

        try {
            $resolved = $client->resolveFolder($workspace, $validated['folder_path']);
        } catch (\Throwable $e) {
            Log::warning('Dropbox folder binding: resolveFolder failed', [
                'organization_id' => $organization->id,
                'folder_path' => $validated['folder_path'],
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages(['folder_path' => "Couldn't find that folder in the connected Dropbox account."]);
        }

        DropboxFolderBinding::updateOrCreate(
            ['dropbox_workspace_id' => $workspace->id, 'folder_id' => $resolved['folder_id']],
            ['folder_path' => $resolved['folder_path'], 'project_id' => $project->id]
        );

        return to_route('organizations.index', ['org' => $organization->id, 'tab' => 'configuration'])->with('status', 'dropbox-folder-bound');
    }

    public function destroy(Organization $organization, DropboxFolderBinding $binding): RedirectResponse
    {
        Gate::authorize('update', $organization);

        abort_unless($binding->dropboxWorkspace->organization_id === $organization->id, 404);

        $binding->delete();

        return to_route('organizations.index', ['org' => $organization->id, 'tab' => 'configuration'])->with('status', 'dropbox-folder-unbound');
    }
}
