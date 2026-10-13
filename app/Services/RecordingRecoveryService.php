<?php

namespace App\Services;

use App\Jobs\AssembleBrowserRecording;
use App\Jobs\TranscribeRecording;
use App\Models\Document;
use App\Models\TusUpload;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "this recording didn't get transcribed" panel on a recording's transcript and Meeting
 * Notes pages: spotting a recording that failed or got stuck, sending it through again, and
 * handing over its audio as a backup — whether that audio is already attached or still only
 * exists as the uploaded chunks AssembleBrowserRecording hasn't joined yet.
 */
class RecordingRecoveryService
{
    /**
     * Long enough for even a multi-hour recording to have been transcribed normally — past
     * this with no transcript and no error, something stalled.
     */
    public const STUCK_AFTER_MINUTES = 60;

    private const RECORDING_SOURCES = ['mobile_recording', 'browser_capture'];

    /**
     * The recording behind a page: the transcript document itself, or — on its Meeting Notes
     * page — the transcript it came from. Null for anything that isn't an audio recording.
     */
    public function recordingFor(Document $document): ?Document
    {
        if ($this->isRecording($document)) {
            return $document;
        }

        $parent = $document->parent;

        return $parent instanceof Document && $this->isRecording($parent) ? $parent : null;
    }

    /**
     * What the panel shows, or null when the recording is fine (transcribed, or still well
     * within normal transcription time).
     *
     * @return array{document_id: string, reason: string, has_audio: bool, can_retry: bool}|null
     */
    public function problemFor(Document $recording): ?array
    {
        if (trim((string) $recording->content) !== '') {
            return null;
        }

        $metadata = $recording->metadata ?? [];
        $error = $metadata['transcription_error'] ?? null;

        if (! is_string($error) || $error === '') {
            $lastAttempt = $this->lastAttemptAt($recording);
            if ($lastAttempt === null || $lastAttempt->gt(now()->subMinutes(self::STUCK_AFTER_MINUTES))) {
                return null;
            }

            $error = 'This recording still hasn\'t been transcribed. The audio is saved.';
        }

        $hasAudio = $this->audioMedia($recording) !== null || $this->chunks($recording)->isNotEmpty();

        return [
            'document_id' => $recording->id,
            'reason' => $error,
            'has_audio' => $hasAudio,
            'can_retry' => $hasAudio && ($metadata['audio_too_large'] ?? false) !== true,
        ];
    }

    /**
     * Picks the recording back up from whichever step didn't finish: transcribing audio that
     * is attached, or assembling audio that's still in chunks. Returns false when there's no
     * audio left to work with.
     */
    public function retry(Document $recording): bool
    {
        $metadata = $recording->metadata ?? [];

        if ($this->audioMedia($recording) !== null) {
            unset($metadata['transcription_error'], $metadata['transcription_id']);
            $recording->update(['metadata' => [...$metadata, 'retried_at' => now()->toIso8601String()]]);
            TranscribeRecording::dispatch($recording);

            return true;
        }

        if ($this->chunks($recording)->isNotEmpty() && ($metadata['audio_too_large'] ?? false) !== true) {
            unset($metadata['transcription_error']);
            $recording->update(['metadata' => [...$metadata, 'retried_at' => now()->toIso8601String()]]);
            AssembleBrowserRecording::dispatch($recording);

            return true;
        }

        return false;
    }

    /**
     * The recording's audio as a download: the attached file when there is one, otherwise the
     * uploaded chunks joined on the fly — the same bytes AssembleBrowserRecording would attach.
     */
    public function download(Document $recording): Media|StreamedResponse|null
    {
        $media = $this->audioMedia($recording);
        if ($media !== null) {
            return $media;
        }

        $chunks = $this->chunks($recording);
        if ($chunks->isEmpty()) {
            return null;
        }

        $isMp4 = str_contains((string) ($chunks->first()->metadata['filetype'] ?? ''), 'mp4');
        $extension = $isMp4 ? 'm4a' : 'webm';
        $filename = str($recording->name ?? 'Recording')->slug()->append('.'.$extension)->toString();

        return response()->streamDownload(function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                $in = fopen(Storage::disk('local')->path($chunk->storage_path), 'rb');
                if ($in === false) {
                    continue;
                }

                fpassthru($in);
                fclose($in);
            }
        }, $filename, ['Content-Type' => $isMp4 ? 'audio/mp4' : 'audio/webm']);
    }

    private function isRecording(Document $document): bool
    {
        return $document->type === config('workflow.intake_key')
            && in_array($document->metadata['recording_source'] ?? null, self::RECORDING_SOURCES, true);
    }

    private function audioMedia(Document $recording): ?Media
    {
        return $recording->getFirstMedia('recording');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TusUpload>
     */
    private function chunks(Document $recording): \Illuminate\Database\Eloquent\Collection
    {
        return TusUpload::inRecordingOrder(
            TusUpload::where('document_id', $recording->id)->whereNotNull('completed_at')->get()
        );
    }

    private function lastAttemptAt(Document $recording): ?CarbonInterface
    {
        $retriedAt = $recording->metadata['retried_at'] ?? null;

        return is_string($retriedAt) ? Carbon::parse($retriedAt) : $recording->created_at;
    }
}
