<?php

namespace App\Jobs;

use App\Events\DocumentProcessingUpdate;
use App\Exceptions\RecordingUnavailableException;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Services\MeetingTranscriptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Throwable;

/**
 * Fetches a provider recording that has no transcript of its own (e.g. a Google Meet meeting
 * recorded without transcripts turned on) and attaches it as the document's 'recording' media,
 * then hands off to TranscribeRecording — from there it's transcribed exactly like a captured
 * or uploaded recording. Runs on the long-running recordings queue, since the file can be up to
 * 2 GB.
 */
class DownloadMeetingRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * A deleted document means the user threw the import away.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Document|OrgDocument $document,
        public string $recordingId,
    ) {
        $this->onConnection(config()->string('queue.recordings.connection'));
        $this->onQueue(config()->string('queue.recordings.queue'));
    }

    public function handle(MeetingTranscriptService $service): void
    {
        $this->document->refresh();

        if ($this->document->getFirstMedia('recording') !== null) {
            TranscribeRecording::dispatch($this->document);

            return;
        }

        $organization = $this->organization();

        if (! $organization?->meeting_provider) {
            $this->markFailed('This organization no longer has a meeting provider set up.');

            return;
        }

        // Checked before anything is downloaded — and again on Try Again, which re-runs this job.
        if ($limitMessage = $organization->transcriptionLimitMessage()) {
            $this->markFailed($limitMessage);

            return;
        }

        $this->broadcast('Downloading recording...', 10);

        Storage::disk('local')->makeDirectory('meeting-recordings');
        $path = Storage::disk('local')->path('meeting-recordings/'.Str::orderedUuid().'.mp4');

        try {
            $service->downloadRecording($organization, $this->recordingId, $path);
            $this->clearFailure();
            $this->document->addMedia($path)->toMediaCollection('recording');
        } catch (RecordingUnavailableException $exception) {
            @unlink($path);
            $this->markFailed($exception->getMessage());

            return;
        } catch (FileIsTooBig) {
            $configuredMax = config('media-library.max_file_size');
            $this->markFailed(
                'This recording ('.Number::fileSize((int) @filesize($path)).') is larger than the '
                .(is_numeric($configuredMax) ? Number::fileSize((int) $configuredMax) : 'allowed').' limit that can be transcribed.',
                ['audio_too_large' => true],
            );
            @unlink($path);

            return;
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        TranscribeRecording::dispatch($this->document);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('DownloadMeetingRecording failed', [
            'document_id' => $this->document->id,
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
        ]);

        $this->markFailed('We couldn\'t download this recording from the meeting provider. Try again in a few minutes.');
    }

    private function organization(): ?Organization
    {
        return $this->document instanceof OrgDocument
            ? $this->document->organization
            : $this->document->project?->client?->organization;
    }

    private function clearFailure(): void
    {
        $metadata = is_array($this->document->metadata) ? $this->document->metadata : [];

        if (array_key_exists('transcription_error', $metadata)) {
            unset($metadata['transcription_error']);
            $this->document->update(['metadata' => $metadata]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markFailed(string $message, array $extra = []): void
    {
        Log::warning('DownloadMeetingRecording: '.$message, ['document_id' => $this->document->id]);

        $this->document->refresh();
        $this->document->update([
            'processed_at' => $this->document->processed_at ?? now(),
            'metadata' => array_merge(is_array($this->document->metadata) ? $this->document->metadata : [], ['transcription_error' => $message], $extra),
        ]);

        $this->broadcast($message, 100);
    }

    private function broadcast(string $message, int $progress): void
    {
        if ($this->document instanceof Document) {
            event(new DocumentProcessingUpdate($this->document, $message, $progress));
        }
    }
}
