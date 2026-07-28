<?php

namespace App\Services;

use App\Jobs\TranscribeRecording;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\UploadedFile;

class RecordingIntakeService
{
    public const RECORDING_MIMES = 'audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,audio/webm';

    /**
     * Shared by both the Sanctum API (Api\RecordingController, for a true native/external
     * client) and the mobile page tree (Mobile\RecordController, session-authenticated) — a
     * recorded meeting reaches this same intake regardless of which client uploaded it. The
     * document exists immediately (processed_at is set so the creation-time observer doesn't
     * dispatch AI processing before there's any real content); TranscribeRecording fills in
     * the content and only then lets the normal Notes -> Action Items step run.
     */
    public function store(Project $project, UploadedFile $audio, ?string $name = null, ?string $recordedAt = null): Document
    {
        $document = $project->documents()->create([
            'type' => config('workflow.intake_key'),
            'name' => $name ?? 'Recording — '.now()->format('M j, Y g:ia'),
            'content' => '',
            'processed_at' => now(),
            'metadata' => [
                'recording_source' => 'mobile_recording',
                'audio_status' => 'pending',
                'recorded_at' => $recordedAt,
            ],
        ]);

        $document->addMedia($audio)->toMediaCollection('recording');

        TranscribeRecording::dispatch($document);

        return $document;
    }

    /**
     * @return array{id: string, project_id: string, project_name: string|null, name: string|null, status: string, audio_status: mixed, created_at: string|null}
     */
    public function summarize(Document $document): array
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
