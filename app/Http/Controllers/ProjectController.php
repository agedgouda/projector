<?php

namespace App\Http\Controllers;

use App\Contracts\LlmDriver;
use App\Http\Requests\ProjectRequest;
use App\Jobs\EvaluateProjectDescription;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Project::class);

        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        $user = $request->user();

        // Security: If not a Super-Admin and not assigned to any organization, deny.
        if (! $user->hasRole('super-admin') && $user->organizations()->doesntExist()) {
            abort(404);
        }

        $projects = Project::visibleTo($user, $orgId)
            ->latest()
            ->get()
            ->withSummary();

        return inertia('Projects/Index', [
            'projects' => $projects,
            'clients' => $user->newCollection([$user])->availableClients($orgId),
            'projectTypes' => ProjectType::all(['id', 'name']),
        ]);
    }

    public function show(Project $project, Request $request, MeetingTranscriptService $service)
    {
        Gate::authorize('view', $project);

        $user = auth()->user();

        // 1. Get projects using your custom collection
        $projects = Project::visibleTo($user)->latest()->get()->withDashboardContext();

        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $user,
                'message' => 'There are no projects available.',
            ]);
        }

        // 3. Extract clients from the user's own collection
        // (Assuming your User model uses the UserCollection)
        $clients = $user->newCollection([$user])->availableClients();

        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        $organization = $project->client->organization;

        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($organization->id);

        $orgRole = $user->roleInOrganization($organization->id);
        $canManageTranscripts = $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead']);

        // Load type and documents (with all needed relationships) before calling
        // getKanbanDocuments(), so it uses the already-loaded collection instead
        // of lazy-loading documents without eager-loaded relationships.
        $project->loadMissing('type');
        $project->load([
            'documents' => fn ($q) => $q->with(['creator', 'editor', 'assignee'])->latest(),
        ]);

        $kanbanData = [(string) $project->id => $project->getKanbanDocuments()];

        return Inertia::render('Projects/Show', [
            'projects' => $projects,
            'currentProject' => $project,
            'kanbanData' => $kanbanData,
            'activeTab' => $tab,
            'clients' => $clients,
            'projectTypes' => $user->hasRole('super-admin')
                ? \App\Models\ProjectType::all(['id', 'name'])
                : \App\Models\ProjectType::where('organization_id', getPermissionsTeamId())->get(['id', 'name']),
            'canManageTranscripts' => $canManageTranscripts,
            'meetingProvider' => $organization->meeting_provider,
            'recordingsData' => Inertia::defer(function () use ($project, $service, $organization, $canManageTranscripts) {
                $importedIds = $project->documents()
                    ->whereNotNull('metadata->recording_id')
                    ->get(['metadata'])
                    ->pluck('metadata.recording_id')
                    ->filter()
                    ->values();

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

                if ($organization->meeting_provider) {
                    try {
                        $all = $service->listRecordings($organization, now()->subDays(30));
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
                    'canManage' => $canManageTranscripts,
                ];
            })->once(),
        ])
            ->toResponse($request)
            ->withCookie(cookie()->forever('last_project_id', $project->id))
            ->withCookie(cookie()->forever('last_active_tab', $tab))
            ->withCookie(cookie()->forever('last_org_id', (string) $organization->id));
    }

    /**
     * Store a newly created project.
     * Uses ProjectRequest to handle context-switching and authorization.
     */
    public function store(ProjectRequest $request)
    {

        try {
            // Validation and Authorization already handled by ProjectRequest
            // But we'll call Gate::authorize here to ensure the standard 403 flow

            Gate::authorize('create', Project::class);

            $project = Project::create($request->validated());

            if (! empty($project->description)) {
                EvaluateProjectDescription::dispatch($project);
            }

            return redirect()->back()->with('success', 'Project successfully created.');

        } catch (\Exception $e) {
            \Log::error('[ProjectController] Store failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 300),
            ]);
            throw $e;
        }
    }

    public function update(ProjectRequest $request, Project $project)
    {
        try {
            Gate::authorize('update', $project);

            $validated = $request->validated();

            if (array_key_exists('description', $validated) && $validated['description'] !== $project->description) {
                $validated['description_quality'] = null;
            }

            $project->update($validated);

            if (! empty($project->description)) {
                EvaluateProjectDescription::dispatch($project);
            }

            return redirect()->back()->with('success', 'Project updated successfully.');

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {

            \Log::error('[ControllerDebug] Authorization Failed', [
                'user_id' => auth()->id(),
                'project_org_id' => $project->organization_id, // Hits our new accessor
                'active_team_id' => getPermissionsTeamId(),
            ]);
            throw $e;
        }
    }

    public function destroy(Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('delete', $project);

        $project->delete();
        $message = 'Project was successfully deleted.';

        $redirectTo = request()->get('redirect_to');
        if ($redirectTo && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return redirect($redirectTo)->with('success', $message);
        }

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function updateLifecycleStep(Request $request, Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->organization_id);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'current_lifecycle_step_id' => 'nullable|integer|exists:lifecycle_steps,id',
        ]);

        $project->update($validated);

        return back();
    }

    public function storeDocument(Request $request, Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'content' => 'required|string',
        ]);

        $project->documents()->create($validated);

        return back()->with('success', 'Document added and indexed.');
    }

    public function evaluateDescription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|min:1',
            'client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $client->organization?->applyDriverConfig();

        /** @var LlmDriver $llmDriver */
        $llmDriver = app(LlmDriver::class);

        $systemPrompt = 'You evaluate project descriptions to determine if they provide useful context for AI document generation. Set "title" to exactly "good" if the description conveys what the project is, who it is for, or what it aims to achieve — even a short, specific description qualifies. Set "title" to exactly "vague" only if the description is so generic that an AI could not meaningfully tailor output to it (e.g. "A project", "Internal tool", "New website"). When vague, use the "criteria" array to list 2-3 short, actionable suggestions for what the user could add to improve it — write them as helpful prompts, not criticisms. Use the content field for a one-sentence explanation.';
        $userPrompt = "Evaluate this project description: \"{$validated['description']}\"\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"content\", and \"criteria\".";

        try {
            $result = $llmDriver->call($systemPrompt, $userPrompt);

            if (($result['status'] ?? '') !== 'success' || empty($result['content'])) {
                return response()->json(['quality' => 'good', 'suggestions' => []]);
            }

            $item = $result['content'][0];
            $verdict = strtolower(trim($item['title'] ?? ''));
            $quality = in_array($verdict, ['good', 'vague']) ? $verdict : 'good';
            $suggestions = $quality === 'vague' ? ($item['criteria'] ?? []) : [];

            return response()->json(['quality' => $quality, 'suggestions' => $suggestions]);
        } catch (\Throwable) {
            return response()->json(['quality' => 'good', 'suggestions' => []]);
        }
    }
}
