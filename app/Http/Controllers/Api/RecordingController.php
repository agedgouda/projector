<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Services\RecordingIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordingController extends Controller
{
    public function __construct(private readonly RecordingIntakeService $recordings) {}

    /**
     * The authenticated user's own mobile-recorded notes and their processing status.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (! $user) {
            abort(401);
        }

        // creator_id is the sufficient check here — Document::visibleTo() additionally
        // requires explicit client-user attachment even for org-admins, which would hide a
        // user's own upload from themselves if they created it via org-level access alone.
        $recordings = Document::query()
            ->where('creator_id', $user->id)
            ->where('metadata->recording_source', 'mobile_recording')
            ->with('project:id,name')
            ->latest()
            ->get(['id', 'project_id', 'name', 'content', 'processed_at', 'metadata', 'created_at']);

        return response()->json([
            'recordings' => $recordings->map(fn (Document $document) => $this->recordings->summarize($document)),
        ]);
    }

    public function show(Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $document->loadMissing('project:id,name');
        $children = Document::where('parent_id', $document->id)->get(['id', 'name', 'type', 'content']);

        return response()->json([
            'recording' => array_merge($this->recordings->summarize($document), [
                'content' => $document->content,
                'children' => $children->map(fn (Document $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'type' => $child->type,
                    'content' => $child->content,
                ]),
            ]),
        ]);
    }

    /**
     * Upload recorded audio and create the note it will become once transcribed.
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
     * Confirm the transcript is accurate and delete the source audio. Deliberately an
     * explicit action rather than automatic-on-transcription, so the source audio survives
     * long enough for the user to actually check the result.
     */
    public function confirm(Document $document): JsonResponse
    {
        Gate::authorize('update', $document);

        $document->update([
            'metadata' => array_merge($document->metadata ?? [], ['audio_status' => 'approved']),
        ]);

        $document->clearMediaCollection('recording');

        return response()->json(['recording' => $this->recordings->summarize($document->refresh())]);
    }
}
