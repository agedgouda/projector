<?php

namespace App\Http\Controllers;

use App\Jobs\ImportMeetingTranscript;
use App\Models\Document;
use App\Models\Project;
use App\Services\DocumentTypeResolver;
use App\Services\Google\GoogleExportService;
use App\Services\IntakeImportService;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MeetingTranscriptController extends Controller
{
    /**
     * Show available recordings and already-imported transcripts for a project.
     */
    public function index(Request $request, Project $project, MeetingTranscriptService $service, GoogleExportService $googleExportService)
    {
        Gate::authorize('view', $project);

        return inertia('Projects/Transcripts', array_merge(
            ['project' => $project->load(['client.organization'])],
            $this->availableRecordingsData($request, $project, $service),
            [
                'googlePickerConfigured' => $googleExportService->pickerConfigured(),
                'googleApiKey' => config('services.google.api_key'),
                'googleAppId' => config('services.google.app_id'),
            ]
        ));
    }

    /**
     * Same data as index() above, as JSON — for the Document Import modal (see
     * Projects/Partials/ImportDocumentOptions.vue), which needs this on demand whenever it
     * opens rather than tied to whatever this page's own deferred prop last happened to load,
     * regardless of which tab the user was on or how long the page had been open for.
     */
    public function available(Request $request, Project $project, MeetingTranscriptService $service): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($this->availableRecordingsData($request, $project, $service));
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, importedIds: \Illuminate\Support\Collection<int, mixed>, crossProjectImportedIds: \Illuminate\Support\Collection<int, mixed>, providerError: string|null, provider: string|null, canManageTranscripts: bool}
     */
    private function availableRecordingsData(Request $request, Project $project, MeetingTranscriptService $service): array
    {
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

        return [
            'recordings' => $recordings,
            'importedIds' => $importedIds,
            'crossProjectImportedIds' => $crossProjectImportedIds,
            'providerError' => $providerError,
            'provider' => $organization?->meeting_provider,
            'canManageTranscripts' => $canManageTranscripts,
        ];
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
     * Queue an import job for a specific recording. Which pipeline it takes is decided purely
     * by the resolved document type, same as every other import source — see
     * DocumentTypeResolver and ImportMeetingTranscript::handle().
     */
    public function store(Request $request, Project $project, IntakeImportService $importer, DocumentTypeResolver $typeResolver): RedirectResponse
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'recording_id' => 'required|string',
            'title' => 'required|string|max:255',
            'started_at' => 'required|string',
            'custom_prompt' => 'nullable|string',
            'type' => 'nullable|string',
            'new_type_label' => 'nullable|string|max:100',
        ]);

        // Prevent duplicate imports — block if imported by this or any other project
        $alreadyImported = Document::where('metadata->recording_id', $validated['recording_id'])->exists();

        if ($alreadyImported) {
            return back()->withErrors(['recording_id' => 'This recording has already been imported into another project.']);
        }

        $metadata = [
            'recording_id' => $validated['recording_id'],
            'provider' => $project->client->organization->meeting_provider,
            'meeting_date' => $validated['started_at'],
        ];

        $resolved = $typeResolver->resolve($project, $validated['type'] ?? null, $validated['new_type_label'] ?? null);

        if ($resolved['type'] === config('workflow.intake_key')) {
            return $importer->import(
                $project,
                $validated['title'],
                $validated['recording_id'],
                null,
                $validated['custom_prompt'] ?? null,
                $metadata,
            );
        }

        // Not a transcript — no AI step, but the recording's content still has to be fetched
        // from the meeting provider (unlike a Google Doc/file, which already has its content in
        // hand the instant it's picked), so this still creates a placeholder and redirects there
        // right away, same as the intake branch above — the user watches it finish importing on
        // its own page instead of staying on this modal. ImportMeetingTranscript::handle() sees
        // the non-intake type and, once the fetch completes, saves it as finished content with
        // no AI step and no pre-created child — the exact same end state a Google Doc/file
        // picked as this same type gets, just necessarily reached one step later.
        $document = $project->documents()->create([
            'type' => $resolved['type'],
            'name' => $validated['title'],
            'content' => '',
            // Suppresses DocumentObserver's auto-AI-dispatch until the fetch job fills this in
            // — irrelevant here since it's not the intake type anyway, but kept for the same
            // reason the intake branch needs it: nothing should touch this document as
            // "finished" before the fetch has actually happened. The document page itself
            // already has a fallback for this exact "looks briefly done, first broadcast
            // corrects it" window (see useDocumentForm.ts's isProcessingLive catch-up logic).
            'processed_at' => now(),
            'metadata' => $metadata,
        ]);

        ImportMeetingTranscript::dispatch($document, $validated['recording_id'], null);

        return redirect()
            ->route('projects.documents.show', [$project, $document])
            ->with('success', "Importing \"{$validated['title']}\"…");
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
