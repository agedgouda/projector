<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Services\RecordingIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordingCaptureController extends Controller
{
    public function __construct(private readonly RecordingIntakeService $recordings) {}

    /**
     * Upload audio captured in-browser from a shared tab/screen (Projects/Partials/
     * BrowserAudioCapture.vue) — same intake as the mobile and Sanctum API recording uploads,
     * just tagged with a different recording_source so PruneUnapprovedRecordings still applies
     * but Api\RecordingController::index (the native client's own recordings list) doesn't pick
     * these up.
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
            'browser_capture',
        );

        return response()->json([
            'recording' => $this->recordings->summarize($document),
        ], 201);
    }

    /**
     * Lightweight polling endpoint the capture panel hits every few seconds while
     * transcription runs in the background.
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
