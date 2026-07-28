<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Services\RecordingIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RecordController extends Controller
{
    public function __construct(private readonly RecordingIntakeService $recordings) {}

    /**
     * Pick which project a new recording belongs to.
     */
    public function index(Request $request): \Inertia\Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $rawOrgId = getPermissionsTeamId();
        $orgId = is_int($rawOrgId) || is_string($rawOrgId) ? (string) $rawOrgId : null;

        $projects = Project::visibleTo($user, $orgId)
            ->whereHas('client', fn ($q) => $q->where('inactive', false))
            ->with('client:id,company_name')
            ->latest()
            ->get(['id', 'name', 'client_id']);

        return Inertia::render('Mobile/Record/Index', [
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client?->company_name,
            ]),
        ]);
    }

    /**
     * The recording screen for a specific project.
     */
    public function show(Project $project): \Inertia\Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Mobile/Record/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
        ]);
    }

    /**
     * Upload the audio captured on this screen, same intake as the Sanctum API's own
     * recording upload — this is just a session-authenticated equivalent for the mobile page
     * tree, which doesn't use Sanctum tokens.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $validated = $request->validate([
            'audio' => 'required|file|mimetypes:'.RecordingIntakeService::RECORDING_MIMES.'|max:204800',
            'name' => 'sometimes|string|max:255',
            'recorded_at' => 'sometimes|date',
        ]);

        $document = $this->recordings->store(
            $project,
            $validated['audio'],
            $validated['name'] ?? null,
            $validated['recorded_at'] ?? null,
        );

        return response()->json([
            'recording' => $this->recordings->summarize($document),
        ], 201);
    }

    /**
     * Lightweight polling endpoint the recording screen hits every few seconds while
     * transcription runs in the background, instead of a full Inertia page render.
     */
    public function status(Project $project, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        return response()->json([
            'recording' => $this->recordings->summarize($document),
        ]);
    }
}
