<?php

namespace App\Jobs;

use App\Contracts\TranscriptionDriver;
use App\Events\DocumentProcessingUpdate;
use App\Models\Document;
use App\Models\OrgDocument;
use App\Services\Ai\AiUsageLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcribes a recording's audio (mobile, browser capture, uploaded file, or a provider
 * recording with no transcript of its own) into the document's content. Transcription is
 * async on the vendor side (a full meeting recording can take minutes to process), so this
 * job submits once, then polls by releasing itself back onto the queue with a delay — the
 * same job instance re-runs from the top each time, using metadata.transcription_id to know
 * which phase it's in.
 *
 * Works on a project Document or a Status Meeting OrgDocument — both keep the audio in their
 * own 'recording' media collection; only what happens once the transcript lands differs (see
 * finish()). Runs on the long-running recordings queue, since submitting means uploading a
 * file of up to 2 GB.
 */
class TranscribeRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Generous — each attempt is a poll cycle, not a real retry of failed work.
     */
    public int $tries = 200;

    /**
     * Backoff for genuine failures (e.g. a network blip calling the vendor API) — distinct
     * from the longer, deliberate POLL_DELAY used between normal poll cycles.
     */
    public int $backoff = 10;

    /**
     * Seconds between poll attempts.
     */
    private const POLL_DELAY = 15;

    public function __construct(public Document|OrgDocument $document)
    {
        $this->onConnection(config()->string('queue.recordings.connection'));
        $this->onQueue(config()->string('queue.recordings.queue'));
    }

    public function handle(TranscriptionDriver $driver): void
    {
        // ~50 minutes of polling with no result — treat as stuck rather than polling forever.
        if ($this->attempts() > 200) {
            $this->giveUp('Transcription timed out.');

            return;
        }

        $this->document->refresh();

        // Another run (e.g. one started from the Try Again button while this one was still
        // polling) already got the transcript — nothing left to do.
        if (trim((string) $this->document->content) !== '') {
            return;
        }

        $metadata = $this->document->metadata ?? [];
        $transcriptId = $metadata['transcription_id'] ?? null;

        if (! is_string($transcriptId)) {
            $this->submit($driver);

            return;
        }

        $this->pollFor($driver, $transcriptId);
    }

    private function submit(TranscriptionDriver $driver): void
    {
        $media = $this->document->getFirstMedia('recording');

        if (! $media) {
            $this->giveUp('No recording audio found to transcribe.');

            return;
        }

        $this->broadcast('Uploading audio...', 15);

        $transcriptId = $driver->submit($media->getPath());

        $this->document->update([
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_id' => $transcriptId]),
        ]);

        $this->broadcast('Transcribing audio...', 25);

        $this->release(self::POLL_DELAY);
    }

    private function pollFor(TranscriptionDriver $driver, string $transcriptId): void
    {
        $result = $driver->poll($transcriptId);

        if ($result['status'] === 'completed') {
            $this->logUsage($transcriptId, $result['usage'] ?? null);
        }

        match ($result['status']) {
            'queued', 'processing' => $this->release(self::POLL_DELAY),
            'completed' => $this->finish($result['text'] ?? ''),
            default => $this->giveUp($result['error'] ?? 'Transcription failed for an unknown reason.'),
        };
    }

    /**
     * Logged on every completed transcript — the vendor bills for the audio even when it held
     * no speech. metadata.transcription_usage_logged holds the transcript it was logged for, so a
     * re-run of the same poll (a crash before the transcript saved) isn't billed twice, while a
     * Try Again that submits a new transcript is.
     *
     * @param  array{driver: string, model: string, audio_seconds: int}|null  $usage
     */
    private function logUsage(string $transcriptId, ?array $usage): void
    {
        $metadata = is_array($this->document->metadata) ? $this->document->metadata : [];

        if ($usage === null || ($metadata['transcription_usage_logged'] ?? null) === $transcriptId) {
            return;
        }

        app(AiUsageLogger::class)->logTranscription(
            $usage['driver'],
            $usage['model'],
            $usage['audio_seconds'],
            $this->document instanceof Document ? $this->document->project : null,
            $this->document instanceof OrgDocument ? $this->document->organization_id : null,
        );

        $this->document->updateQuietly([
            'metadata' => array_merge($metadata, ['transcription_usage_logged' => $transcriptId]),
        ]);
    }

    private function finish(string $text): void
    {
        if (trim($text) === '') {
            $this->giveUp('No speech detected in the recording.');

            return;
        }

        $this->broadcast('Saving transcript...', 65);

        if ($this->document instanceof OrgDocument) {
            // Same hand-off ImportOrgMeetingTranscript makes once it has a provider transcript.
            $this->document->updateQuietly([
                'content' => $text,
                'processed_at' => null,
                'metadata' => array_merge($this->document->metadata ?? [], [
                    'ai_draft' => ['status' => 'processing'],
                ]),
            ]);

            GenerateOrgDocumentEmbedding::dispatch($this->document);
            ProcessOrgDocumentAI::dispatch($this->document);

            return;
        }

        if ($this->document->type !== config('workflow.intake_key')) {
            // A provider recording picked with "Import As" set to some other type — finished
            // content with no AI step, exactly like ImportMeetingTranscript's non-intake branch.
            $this->document->update(['content' => $text, 'processed_at' => now()]);
            $this->broadcast('Import complete.', 100);

            return;
        }

        // Save quietly, matching ImportMeetingTranscript's convention — the observer would
        // otherwise dispatch GenerateDocumentEmbedding before AI processing has run.
        $this->document->content = $text;
        $this->document->processed_at = null;
        $this->document->saveQuietly();

        ProcessDocumentAI::dispatchUnlessProcessing($this->document);
    }

    /**
     * Only a project Document has a live progress channel; a Status Meeting just shows its
     * result once it's processed.
     */
    private function broadcast(string $message, int $progress): void
    {
        if ($this->document instanceof Document) {
            event(new DocumentProcessingUpdate($this->document, $message, $progress));
        }
    }

    private function giveUp(string $message): void
    {
        Log::warning('TranscribeRecording: '.$message, ['document_id' => $this->document->id]);

        $this->document->update([
            'processed_at' => $this->document->processed_at ?? now(),
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_error' => $message]),
        ]);

        $this->broadcast($message, 100);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('TranscribeRecording failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->document->update([
            'processed_at' => $this->document->processed_at ?? now(),
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_error' => $exception->getMessage()]),
        ]);

        $this->broadcast('Transcription failed: '.$exception->getMessage(), 0);
    }
}
