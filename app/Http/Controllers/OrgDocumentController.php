<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrgDocumentRequest;
use App\Jobs\ImportOrgMeetingTranscript;
use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\Project;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrgDocumentController extends Controller
{
    public function index(Request $request, MeetingTranscriptService $service)
    {
        $user = $request->user();
        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        $organization = $user->hasRole('super-admin')
            ? Organization::findOrFail($orgId)
            : $user->organizations()->findOrFail($orgId);

        setPermissionsTeamId($organization->id);
        Gate::authorize('viewAny', [OrgDocument::class, $organization]);

        $statusMeetings = $organization->orgDocuments()
            ->with('creator')
            ->latest()
            ->get();

        $userOrganizations = $user->hasRole('super-admin')
            ? Organization::all(['id', 'name'])
            : $user->organizations()->get(['organizations.id', 'organizations.name']);

        $canManage = $user->can('create', [OrgDocument::class, $organization]);

        return inertia('StatusMeetings/Index', [
            'currentOrg' => $organization->only('id', 'name'),
            'organizations' => $userOrganizations,
            'statusMeetings' => $statusMeetings,
            'canManage' => $canManage,
            'meetingProvider' => $organization->meeting_provider,
            'recordingsData' => Inertia::defer(function () use ($organization, $service) {
                $importedIds = OrgDocument::where('organization_id', $organization->id)
                    ->whereNotNull('metadata->recording_id')
                    ->get(['metadata'])
                    ->pluck('metadata.recording_id')
                    ->filter()
                    ->values();

                $recordings = [];
                $providerError = null;

                if ($organization->meeting_provider) {
                    try {
                        $recordings = $service->listRecordings($organization, now()->subDays(30));
                    } catch (\Throwable $e) {
                        $providerError = $e->getMessage();
                    }
                }

                return [
                    'recordings' => $recordings,
                    'importedIds' => $importedIds,
                    'providerError' => $providerError,
                ];
            })->once(),
        ])->toResponse($request)
            ->withCookie(cookie()->forever('last_org_id', (string) $organization->id));
    }

    public function create(Organization $organization)
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

        return inertia('Organizations/Documents/Create', [
            'organization' => $organization->only('id', 'name'),
        ]);
    }

    public function importFromRecording(Request $request, Organization $organization): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

        $validated = $request->validate([
            'recording_id' => 'required|string',
            'title' => 'required|string|max:255',
            'started_at' => 'required|string',
        ]);

        $orgDocument = $organization->orgDocuments()->create([
            'type' => 'status_meeting',
            'name' => $validated['title'],
            'content' => '',
            'processed_at' => now(),
            'metadata' => [
                'recording_id' => $validated['recording_id'],
                'provider' => $organization->meeting_provider,
                'meeting_date' => $validated['started_at'],
            ],
        ]);

        ImportOrgMeetingTranscript::dispatch($orgDocument, $validated['recording_id']);

        return back()->with('success', "Importing \"{$validated['title']}\"…");
    }

    public function store(StoreOrgDocumentRequest $request, Organization $organization): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

        $orgDocument = $organization->orgDocuments()->create($request->validated());

        return redirect()
            ->route('organizations.documents.show', ['organization' => $organization->id, 'orgDocument' => $orgDocument->id])
            ->with('success', 'Status meeting created successfully.');
    }

    public function show(Organization $organization, OrgDocument $orgDocument, MeetingTranscriptService $service)
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('view', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $user = request()->user();

        $activeProjects = Project::whereHas('client', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('inactive', false)
            ->with('client:id,name')
            ->get(['id', 'name', 'client_id'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'client_name' => $p->client->name ?? '']);

        return inertia('Organizations/Documents/Show', [
            'organization' => $organization->only('id', 'name'),
            'item' => $orgDocument->load(['creator', 'editor']),
            'canManage' => $user->can('update', $orgDocument),
            'activeProjects' => $activeProjects,
            'meetingProvider' => $organization->meeting_provider,
            'recordingsData' => Inertia::defer(function () use ($organization, $service) {
                $importedIds = OrgDocument::where('organization_id', $organization->id)
                    ->whereNotNull('metadata->recording_id')
                    ->get(['metadata'])
                    ->pluck('metadata.recording_id')
                    ->filter()
                    ->values();

                $recordings = [];
                $providerError = null;

                if ($organization->meeting_provider) {
                    try {
                        $recordings = $service->listRecordings($organization, now()->subDays(30));
                    } catch (\Throwable $e) {
                        $providerError = $e->getMessage();
                    }
                }

                return [
                    'recordings' => $recordings,
                    'importedIds' => $importedIds,
                    'providerError' => $providerError,
                ];
            })->once(),
        ]);
    }

    public function importRecording(Request $request, Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'recording_id' => 'required|string',
            'title' => 'required|string|max:255',
            'started_at' => 'required|string',
        ]);

        $orgDocument->update([
            'name' => $orgDocument->name ?: $validated['title'],
            'processed_at' => now(),
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'recording_id' => $validated['recording_id'],
                'provider' => $organization->meeting_provider,
                'meeting_date' => $validated['started_at'],
            ]),
        ]);

        ImportOrgMeetingTranscript::dispatch($orgDocument, $validated['recording_id']);

        return back()->with('success', "Importing \"{$validated['title']}\"…");
    }

    public function processDraft(Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $orgDocument->updateQuietly([
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'ai_draft' => ['status' => 'processing'],
            ]),
        ]);

        ProcessOrgDocumentAI::dispatch($orgDocument);

        return back()->with('success', 'Extracting action items…');
    }

    public function saveDraft(Request $request, Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'groups' => 'required|array',
        ]);

        $orgDocument->updateQuietly([
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'ai_draft' => [
                    'status' => 'pending_review',
                    'groups' => $validated['groups'],
                ],
            ]),
        ]);

        return back();
    }

    public function commitDraft(Request $request, Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'groups' => 'required|array',
            'groups.*.project_id' => 'required|uuid|exists:projects,id',
            'groups.*.action_items' => 'required|array|min:1',
            'groups.*.action_items.*.content' => 'required|string|max:2000',
        ]);

        foreach ($validated['groups'] as $group) {
            $project = Project::findOrFail($group['project_id']);

            foreach ($group['action_items'] as $item) {
                $project->documents()->create([
                    'type' => 'action_item',
                    'name' => mb_strimwidth($item['content'], 0, 100, '…'),
                    'content' => $item['content'],
                    'metadata' => ['source_org_document_id' => $orgDocument->id],
                ]);
            }
        }

        $orgDocument->updateQuietly([
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'ai_draft' => ['status' => 'committed'],
            ]),
            'processed_at' => now(),
        ]);

        return back()->with('success', 'Action items committed to projects.');
    }

    public function update(StoreOrgDocumentRequest $request, Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $orgDocument->update($request->validated());

        return back()->with('success', 'Status meeting updated.');
    }

    public function destroy(Organization $organization, OrgDocument $orgDocument): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('delete', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $orgDocument->delete();

        return redirect()
            ->route('status-meetings.index')
            ->with('success', 'Status meeting deleted.');
    }
}
