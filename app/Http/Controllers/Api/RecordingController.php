<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeRecording;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordingController extends Controller
{
    private const RECORDING_MIMES = 'audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,audio/webm';

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
            'recordings' => $recordings->map(fn (Document $document) => $this->summarize($document)),
        ]);
    }

    public function show(Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $document->loadMissing('project:id,name');
        $children = Document::where('parent_id', $document->id)->get(['id', 'name', 'type', 'content']);

        return response()->json([
            'recording' => array_merge($this->summarize($document), [
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
     * Upload recorded audio and create the note it will become once transcribed. The
     * document exists immediately (processed_at is set so the creation-time observer
     * doesn't dispatch AI processing before there's any real content); TranscribeRecording
     * fills in the content and only then lets the normal Notes -> Action Items step run.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $validated = $request->validate([
            'audio' => 'required|file|mimetypes:'.self::RECORDING_MIMES.'|max:204800',
            'name' => 'sometimes|string|max:255',
            'recorded_at' => 'sometimes|date',
        ]);

        $document = $project->documents()->create([
            'type' => config('workflow.intake_key'),
            'name' => $validated['name'] ?? 'Recording — '.now()->format('M j, Y g:ia'),
            'content' => '',
            'processed_at' => now(),
            'metadata' => [
                'recording_source' => 'mobile_recording',
                'audio_status' => 'pending',
                'recorded_at' => $validated['recorded_at'] ?? null,
            ],
        ]);

        $document->addMediaFromRequest('audio')->toMediaCollection('recording');

        TranscribeRecording::dispatch($document);

        return response()->json([
            'recording' => $this->summarize($document),
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

        return response()->json(['recording' => $this->summarize($document->refresh())]);
    }

    /**
     * @return array{id: string, project_id: string, project_name: string|null, name: string|null, status: string, audio_status: mixed, created_at: string|null}
     */
    private function summarize(Document $document): array
    {
        $metadata = $document->metadata ?? [];

        // processed_at is intentionally set on the placeholder document too (to suppress the
        // creation-time observer), so it can't distinguish "not started" from "done" — content
        // emptiness is the real signal for whether transcription has actually landed yet.
        $status = match (true) {
            ! empty($metadata['transcription_error']) => 'failed',
            empty($document->content) => 'processing',
            default => 'processed',
        };

        return [
            'id' => $document->id,
            'project_id' => $document->project_id,
            'project_name' => $document->project?->name,
            'name' => $document->name,
            'status' => $status,
            'audio_status' => $metadata['audio_status'] ?? null,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
