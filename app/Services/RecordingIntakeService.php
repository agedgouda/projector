<?php

namespace App\Services;

use App\Jobs\TranscribeRecording;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;

class RecordingIntakeService
{
    // video/webm is intentional, not a mistake — libmagic's content-sniffing (which is what
    // Laravel's mimetypes rule actually validates against, not the browser's reported
    // Content-Type) frequently identifies an audio-only WebM/Opus recording as video/webm,
    // since the WebM container doesn't signal "audio-only" at the level libmagic inspects.
    // Every upload path funneling through here (mobile, Sanctum API, browser capture) only
    // ever sends what MediaRecorder produced from an audio-only stream, so this never actually
    // admits real video.
    public const RECORDING_MIMES = 'audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,audio/webm,video/webm';

    /**
     * Shared by both the Sanctum API (Api\RecordingController, for a true native/external
     * client) and the mobile page tree (Mobile\RecordController, session-authenticated) — a
     * recorded meeting reaches this same intake regardless of which client uploaded it. The
     * document exists immediately (processed_at is set so the creation-time observer doesn't
     * dispatch AI processing before there's any real content); TranscribeRecording fills in
     * the content and only then lets the normal Notes -> Action Items step run.
     */
    public function store(Project $project, UploadedFile $audio, ?string $name = null, ?string $recordedAt = null, string $source = 'mobile_recording'): Document
    {
        return $this->finalize($project, $audio, $audio->getSize() !== false ? $audio->getSize() : 0, $name, $recordedAt, $source);
    }

    /**
     * Same intake as store() above, for a file that's already sitting on disk rather than
     * arriving as a single HTTP upload — used by TusUploadController once a browser-captured
     * recording's chunks have been concatenated into one file. $sizeBytes is passed in rather
     * than re-stat'd here because the caller (the TusUpload rows) already knows it exactly.
     */
    public function storeFromPath(Project $project, string $path, int $sizeBytes, ?string $name = null, ?string $recordedAt = null, string $source = 'browser_capture'): Document
    {
        return $this->finalize($project, $path, $sizeBytes, $name, $recordedAt, $source);
    }

    private function finalize(Project $project, UploadedFile|string $audio, int $sizeBytes, ?string $name, ?string $recordedAt, string $source): Document
    {
        // Everything below is one transaction so a media-attachment failure (oversized file,
        // full disk, corrupt upload, ...) rolls back the placeholder document(s) it already
        // created instead of leaving a permanently empty, unrecoverable intake document behind
        // — that used to happen silently, with nothing surfaced to the user at all.
        $document = DB::transaction(function () use ($project, $audio, $sizeBytes, $name, $recordedAt, $source) {
            $document = $project->documents()->create([
                'type' => config('workflow.intake_key'),
                'name' => $name ?? 'Recording — '.now()->format('M j, Y g:ia'),
                'content' => '',
                'processed_at' => now(),
                'metadata' => [
                    'recording_source' => $source,
                    'audio_status' => 'pending',
                    'recorded_at' => $recordedAt,
                ],
            ]);

            try {
                // Spatie accepts either an UploadedFile or a plain path string here; for a
                // path it copies the file into the media collection's own storage and then
                // deletes the source — exactly what we want for a tus scratch file, which has
                // no further use once this succeeds.
                $document->addMedia($audio)->toMediaCollection('recording');
            } catch (FileIsTooBig) {
                // Not $e->getMessage() — Spatie's own text includes the raw server temp path,
                // which is neither useful nor appropriate to show a user. Our own validation
                // rule already covers the normal case (mimetype/204800 KB); this is the
                // defense-in-depth backstop for whenever the two disagree (see
                // config/media-library.php's max_file_size, which should match).
                $configuredMax = config('media-library.max_file_size');
                $maxLabel = is_numeric($configuredMax) ? Number::fileSize((int) $configuredMax) : 'the configured limit';

                throw ValidationException::withMessages([
                    'audio' => 'This recording ('.Number::fileSize($sizeBytes).') is too large to upload — the maximum is '.$maxLabel.'.',
                ]);
            } catch (FileCannotBeAdded) {
                // Any other media-library rejection (unreadable file, disk failure, ...) —
                // distinct from the size case above so that one doesn't get mislabeled as this.
                throw ValidationException::withMessages([
                    'audio' => 'This recording could not be processed. Please try again.',
                ]);
            }

            // Same pre-creation IntakeImportService::import() uses for every other transcript
            // source (Google Doc, file, provider-imported recording) — the user should never
            // land on the raw transcript page, so a blank Meeting Notes child is created up
            // front whenever the "Transcript to Meeting Notes" template is single_output.
            // ProcessDocumentAI::handle() finds this one pre-existing child and fills it in
            // place once TranscribeRecording -> AI actually runs, so its id (and anything
            // already pointed at it) never changes.
            $templateId = config('workflow.intake_to_action_items_ai_template_id');
            $isSingleOutput = is_int($templateId) && (bool) AiTemplate::find($templateId)?->single_output;

            if ($isSingleOutput) {
                $project->documents()->create([
                    'parent_id' => $document->id,
                    'type' => config('workflow.action_items_key'),
                    'name' => $document->name,
                    'content' => '',
                ]);
            }

            return $document;
        });

        // Dispatched only after the transaction commits — a rollback above must never leave a
        // queued job pointed at a document that no longer exists.
        TranscribeRecording::dispatch($document);

        return $document;
    }

    /**
     * @return array{id: string, project_id: string, project_name: string|null, name: string|null, status: string, audio_status: mixed, created_at: string|null, notes_id: string|null}
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
            // The pre-created Meeting Notes child (see store()) — once present, the caller
            // should send the user there instead of this raw transcript, exactly like every
            // other import source. Looked up fresh rather than cached anywhere, since
            // ProcessDocumentAI fills this same row in place rather than replacing it.
            'notes_id' => $document->children()
                ->where('type', config('workflow.action_items_key'))
                ->first()?->id,
        ];
    }
}
