<?php

namespace App\Http\Controllers;

use App\Models\DropboxFolderBinding;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mirrors OrganizationSlackChannelsController, one folder standing in for one channel — the
 * frontend picks from a real DropboxApiClient::listTopLevelFolders() dropdown (see
 * OrganizationController::dropboxFolderData()) the same way Slack's channel picker uses a real
 * conversations.list, so store() trusts the submitted id + path directly rather than re-resolving
 * a human-typed one — a prior version asked for a typed path and looked it up server-side, which
 * only ever produced "couldn't find that folder" for a real, existing folder (a bug in how the
 * lookup read Dropbox's response, since fixed — but asking a human to type an exact Dropbox path
 * correctly was always going to be error-prone besides).
 */
class OrganizationDropboxFoldersController extends Controller
{
    /**
     * Create or repoint a folder's binding. updateOrCreate rather than create, so re-submitting
     * for a folder that's already bound just repoints it at the newly chosen project instead of
     * erroring on the (dropbox_workspace_id, folder_id) unique constraint.
     */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        if ($organization->dropboxWorkspace === null) {
            abort(404);
        }

        $validated = $request->validate([
            'folder_id' => 'required|string',
            'folder_path' => 'required|string',
            'project_id' => 'required|uuid',
        ]);

        /** @var User $user */
        $user = $request->user();

        $project = Project::visibleTo($user, $organization->id)
            ->whereKey($validated['project_id'])
            ->firstOrFail();

        DropboxFolderBinding::updateOrCreate(
            ['dropbox_workspace_id' => $organization->dropboxWorkspace->id, 'folder_id' => $validated['folder_id']],
            ['folder_path' => $validated['folder_path'], 'project_id' => $project->id]
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
