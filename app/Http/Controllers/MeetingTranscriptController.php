<?php

namespace App\Http\Controllers;

use App\Jobs\ImportMeetingTranscript;
use App\Models\Document;
use App\Models\Project;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MeetingTranscriptController extends Controller
{
    /**
     * Show available recordings and already-imported transcripts for a project.
     */
    public function index(Request $request, Project $project, MeetingTranscriptService $service)
    {
        Gate::authorize('view', $project);

        $user = $request->user();
        $organization = $project->client->organization;

        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($organization->id);

        $orgRole = $user->roleInOrganization($organization->id);
        $canManageTranscripts = $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead']);

        // IDs of recordings already imported into this project
        $importedIds = $project->documents()
            ->whereNotNull('metadata->recording_id')
            ->get(['metadata'])
            ->pluck('metadata.recording_id')
            ->filter()
            ->values();

        // IDs of recordings already imported into any other project
        $crossProjectImportedIds = Document::whereNotNull('metadata->recording_id')
            ->where('project_id', '!=', $project->id)
            ->get(['metadata'])
            ->pluck('metadata.recording_id')
            ->filter()
            ->diff($importedIds)
            ->values();

        $dismissedIds = $project->dismissedRecordings()->pluck('recording_id');

        $recordings = [];
        $providerError = null;

        if ($organization?->meeting_provider) {
            try {
                $since = now()->subDays(30);
                $all = $service->listRecordings($organization, $since);
                $recordings = array_values(array_filter(
                    $all,
                    fn ($r) => ! $dismissedIds->contains($r['id'])
                ));
            } catch (\Throwable $e) {
                $providerError = $e->getMessage();
            }
        }

        return inertia('Projects/Transcripts', [
            'project' => $project->load(['type', 'client.organization']),
            'recordings' => $recordings,
            'importedIds' => $importedIds,
            'crossProjectImportedIds' => $crossProjectImportedIds,
            'providerError' => $providerError,
            'provider' => $organization?->meeting_provider,
            'canManageTranscripts' => $canManageTranscripts,
        ]);
    }

    private function authorizeManage(Request $request, Project $project): void
    {
        $user = $request->user();
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($project->client->organization_id);

        $orgRole = $user->roleInOrganization($project->client->organization_id);
        $canManage = $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead']);

        abort_if(! $canManage, Response::HTTP_FORBIDDEN);
    }

    /**
     * Queue an import job for a specific recording.
     */
    public function store(Request $request, Project $project)
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'recording_id' => 'required|string',
            'title' => 'required|string|max:255',
            'started_at' => 'required|string',
        ]);

        // Prevent duplicate imports — block if imported by this or any other project
        $alreadyImported = Document::where('metadata->recording_id', $validated['recording_id'])->exists();

        if ($alreadyImported) {
            return back()->withErrors(['recording_id' => 'This recording has already been imported into another project.']);
        }

        // Create a placeholder document immediately so the UI can track progress.
        // Use processed_at = now() temporarily to prevent the DocumentObserver from
        // dispatching ProcessDocumentAI before the transcript content has been fetched.
        $document = $project->documents()->create([
            'type' => 'intake',
            'name' => $validated['title'],
            'content' => '',
            'processed_at' => now(),
            'metadata' => [
                'recording_id' => $validated['recording_id'],
                'provider' => $project->client->organization->meeting_provider,
                'meeting_date' => $validated['started_at'],
            ],
        ]);

        ImportMeetingTranscript::dispatch($document, $validated['recording_id']);

        return back()->with('success', "Importing \"{$validated['title']}\"…");
    }

    /**
     * Dismiss a recording so it no longer appears in the available list.
     */
    public function destroy(Request $request, Project $project): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'recording_id' => 'required|string',
        ]);

        $project->dismissedRecordings()->firstOrCreate([
            'recording_id' => $validated['recording_id'],
        ]);

        return back();
    }
}
