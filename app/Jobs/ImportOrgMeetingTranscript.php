<?php

namespace App\Jobs;

use App\Models\OrgDocument;
use App\Services\MeetingTranscriptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportOrgMeetingTranscript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 10;

    public function __construct(
        public readonly OrgDocument $orgDocument,
        public readonly string $recordingId,
    ) {}

    public function handle(MeetingTranscriptService $service): void
    {
        $organization = $this->orgDocument->organization;

        if (! $organization?->meeting_provider) {
            Log::warning("ImportOrgMeetingTranscript: no meeting provider for org [{$this->orgDocument->organization_id}].");
            $this->orgDocument->update(['processed_at' => now()]);

            return;
        }

        $transcriptText = $service->fetchTranscript($organization, $this->recordingId);

        if (empty(trim($transcriptText))) {
            Log::warning("ImportOrgMeetingTranscript: empty transcript for recording [{$this->recordingId}].");
            $this->orgDocument->update(['processed_at' => now()]);

            return;
        }

        // Save transcript content without triggering the observer so it doesn't
        // prematurely dispatch GenerateOrgDocumentEmbedding before content is set.
        $this->orgDocument->content = $transcriptText;
        $this->orgDocument->processed_at = null;
        $this->orgDocument->saveQuietly();

        GenerateOrgDocumentEmbedding::dispatch($this->orgDocument);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportOrgMeetingTranscript failed', [
            'org_document_id' => $this->orgDocument->id,
            'organization_id' => $this->orgDocument->organization_id,
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if (! $this->orgDocument->processed_at) {
            $this->orgDocument->update(['processed_at' => now()]);
        }
    }
}
