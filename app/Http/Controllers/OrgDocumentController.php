<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrgDocumentRequest;
use App\Jobs\ImportOrgMeetingTranscript;
use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\Project;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $orgDocuments = $organization->orgDocuments()
            ->with('creator')
            ->latest()
            ->get();

        $orgDocumentIds = $orgDocuments->pluck('id')->filter()->values();

        $linkedDocumentsByOrgId = $orgDocumentIds->isNotEmpty()
            ? Document::whereIn(DB::raw("metadata->>'source_org_document_id'"), $orgDocumentIds->toArray())
                ->with('project.client')
                ->get()
                ->groupBy(fn ($d) => $d->metadata['source_org_document_id'] ?? null)
            : collect();

        $statusMeetings = $orgDocuments->map(function (OrgDocument $meeting) use ($linkedDocumentsByOrgId) {
            $draft = $meeting->metadata['ai_draft'] ?? null;

            return [
                'id' => $meeting->id,
                'name' => $meeting->name,
                'type' => $meeting->type,
                'content' => $meeting->content,
                'processed_at' => $meeting->processed_at,
                'created_at' => $meeting->created_at,
                'creator' => $meeting->creator ? ['name' => $meeting->creator->name] : null,
                'ai_draft_status' => $draft['status'] ?? null,
                'ai_draft_error' => $draft['error'] ?? null,
                'ai_draft_groups' => collect($draft['groups'] ?? [])->map(fn ($g) => [
                    'group_id' => $g['group_id'] ?? null,
                    'project_id' => $g['project_id'] ?? null,
                    'project_name' => $g['project_name'] ?? '',
                    'client_name' => $g['client_name'] ?? '',
                    'document_title' => $g['document_title'] ?? '',
                ])->values()->all(),
                'linked_documents' => $linkedDocumentsByOrgId->get($meeting->id, collect())->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'type' => $d->type,
                    'project_id' => $d->project_id,
                    'project_name' => $d->project?->name ?? '',
                    'client_name' => $d->project?->client?->company_name ?? '',
                ])->values()->all(),
            ];
        })->values()->all();

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

        if (! empty($orgDocument->content)) {
            $orgDocument->updateQuietly([
                'metadata' => array_merge($orgDocument->metadata ?? [], [
                    'ai_draft' => ['status' => 'processing'],
                ]),
            ]);

            ProcessOrgDocumentAI::dispatch($orgDocument);
        }

        return redirect()
            ->route('status-meetings.index')
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

        return inertia('Organizations/Documents/Show', [
            'organization' => $organization->only('id', 'name'),
            'item' => $orgDocument->load(['creator', 'editor']),
            'canManage' => $user->can('update', $orgDocument),
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
            'groups.*.document_title' => 'required|string|max:500',
            'groups.*.document_content' => 'required|string',
        ]);

        foreach ($validated['groups'] as $group) {
            $project = Project::findOrFail($group['project_id']);

            $project->documents()->create([
                'type' => 'action_items',
                'name' => $group['document_title'],
                'content' => $group['document_content'],
                'metadata' => ['source_org_document_id' => $orgDocument->id],
            ]);
        }

        $orgDocument->updateQuietly([
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'ai_draft' => ['status' => 'committed'],
            ]),
            'processed_at' => now(),
        ]);

        return back()->with('success', 'Action items committed to projects.');
    }

    public function showDraftGroup(Organization $organization, OrgDocument $orgDocument, string $groupId)
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('view', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $draft = $orgDocument->metadata['ai_draft'] ?? null;
        $groups = $draft['groups'] ?? [];
        $group = collect($groups)->firstWhere('group_id', $groupId);

        if (! $group) {
            abort(404);
        }

        $user = request()->user();

        $activeProjects = Project::whereHas('client', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('inactive', false)
            ->with('client:id,company_name')
            ->get(['id', 'name', 'client_id'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'client_name' => $p->client->company_name ?? '']);

        $clients = \App\Models\Client::where('organization_id', $organization->id)
            ->where('inactive', false)
            ->get(['id', 'company_name']);

        $projectTypes = \App\Models\ProjectType::where(function ($q) use ($organization) {
            $q->whereNull('organization_id')->orWhere('organization_id', $organization->id);
        })->get(['id', 'name']);

        return inertia('Organizations/Documents/DraftGroup', [
            'organization' => $organization->only('id', 'name'),
            'orgDocument' => $orgDocument->only('id', 'name'),
            'group' => $group,
            'canManage' => $user->can('update', $orgDocument),
            'activeProjects' => $activeProjects,
            'clients' => $clients,
            'projectTypes' => $projectTypes,
        ]);
    }

    public function commitDraftGroup(Request $request, Organization $organization, OrgDocument $orgDocument, string $groupId): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $orgDocument);

        if ($orgDocument->organization_id !== $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'project_id' => 'required|uuid|exists:projects,id',
            'document_title' => 'required|string|max:500',
            'document_content' => 'required|string',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        $project->documents()->create([
            'type' => 'action_items',
            'name' => $validated['document_title'],
            'content' => $validated['document_content'],
            'metadata' => ['source_org_document_id' => $orgDocument->id],
        ]);

        $draft = $orgDocument->metadata['ai_draft'] ?? [];
        $remainingGroups = collect($draft['groups'] ?? [])->reject(fn ($g) => ($g['group_id'] ?? null) === $groupId)->values()->all();

        $newStatus = count($remainingGroups) === 0 ? 'committed' : 'pending_review';

        $orgDocument->updateQuietly([
            'metadata' => array_merge($orgDocument->metadata ?? [], [
                'ai_draft' => [
                    'status' => $newStatus,
                    'groups' => $remainingGroups,
                ],
            ]),
        ]);

        return redirect()
            ->route('status-meetings.index', ['expand' => $orgDocument->id])
            ->with('success', 'Action items committed to '.$project->name.'.');
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
