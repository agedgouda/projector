<?php

namespace App\Jobs;

use App\Events\DocumentProcessingUpdate;
use App\Models\Document;
use App\Services\MeetingTranscriptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportMeetingTranscript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 10;

    public function __construct(
        public readonly Document $document,
        public readonly string $recordingId,
    ) {}

    public function handle(MeetingTranscriptService $service): void
    {
        $organization = $this->document->project->client->organization;

        if (! $organization?->meeting_provider) {
            Log::warning("ImportMeetingTranscript: no meeting provider for project [{$this->document->project_id}].");
            $this->document->update(['processed_at' => now()]);

            return;
        }

        event(new DocumentProcessingUpdate($this->document, 'Fetching transcript...', 15));

        $transcriptText = $service->fetchTranscript($organization, $this->recordingId);

        if (empty(trim($transcriptText))) {
            Log::warning("ImportMeetingTranscript: empty transcript for recording [{$this->recordingId}].");
            $this->document->update(['processed_at' => now()]);
            event(new DocumentProcessingUpdate($this->document, 'No transcript content found.', 100));

            return;
        }

        event(new DocumentProcessingUpdate($this->document, 'Saving transcript...', 65));

        $this->document->update(['content' => $transcriptText]);

        GenerateDocumentEmbedding::dispatch($this->document);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportMeetingTranscript failed', [
            'document_id' => $this->document->id,
            'project_id' => $this->document->project_id,
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if (! $this->document->processed_at) {
            $this->document->update(['processed_at' => now()]);
        }

        event(new DocumentProcessingUpdate(
            $this->document,
            'Import failed: '.$exception->getMessage(),
            0
        ));
    }
}
