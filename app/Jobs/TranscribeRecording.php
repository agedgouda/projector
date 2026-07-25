<?php

namespace App\Jobs;

use App\Contracts\TranscriptionDriver;
use App\Events\DocumentProcessingUpdate;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcribes a mobile-recorded audio note into the document's content. Transcription is
 * async on the vendor side (a full meeting recording can take minutes to process), so this
 * job submits once, then polls by releasing itself back onto the queue with a delay — the
 * same job instance re-runs from the top each time, using metadata.transcription_id to know
 * which phase it's in.
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

    public function __construct(public Document $document) {}

    public function handle(TranscriptionDriver $driver): void
    {
        // ~50 minutes of polling with no result — treat as stuck rather than polling forever.
        if ($this->attempts() > 200) {
            $this->giveUp('Transcription timed out.');

            return;
        }

        $this->document->refresh();
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

        event(new DocumentProcessingUpdate($this->document, 'Uploading audio...', 15));

        $transcriptId = $driver->submit($media->getPath());

        $this->document->update([
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_id' => $transcriptId]),
        ]);

        event(new DocumentProcessingUpdate($this->document, 'Transcribing audio...', 25));

        $this->release(self::POLL_DELAY);
    }

    private function pollFor(TranscriptionDriver $driver, string $transcriptId): void
    {
        $result = $driver->poll($transcriptId);

        match ($result['status']) {
            'queued', 'processing' => $this->release(self::POLL_DELAY),
            'completed' => $this->finish($result['text'] ?? ''),
            default => $this->giveUp($result['error'] ?? 'Transcription failed for an unknown reason.'),
        };
    }

    private function finish(string $text): void
    {
        if (trim($text) === '') {
            $this->giveUp('No speech detected in the recording.');

            return;
        }

        event(new DocumentProcessingUpdate($this->document, 'Saving transcript...', 65));

        // Save quietly, matching ImportMeetingTranscript's convention — the observer would
        // otherwise dispatch GenerateDocumentEmbedding before AI processing has run.
        $this->document->content = $text;
        $this->document->processed_at = null;
        $this->document->saveQuietly();

        ProcessDocumentAI::dispatch($this->document);
    }

    private function giveUp(string $message): void
    {
        Log::warning('TranscribeRecording: '.$message, ['document_id' => $this->document->id]);

        $this->document->update([
            'processed_at' => $this->document->processed_at ?? now(),
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_error' => $message]),
        ]);

        event(new DocumentProcessingUpdate($this->document, $message, 100));
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

        event(new DocumentProcessingUpdate(
            $this->document,
            'Transcription failed: '.$exception->getMessage(),
            0
        ));
    }
}
