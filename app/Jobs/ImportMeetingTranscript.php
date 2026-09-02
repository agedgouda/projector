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

    /**
     * Exactly one of $recordingId / $content is given — a picked meeting recording supplies
     * $recordingId and this job fetches its transcript from the provider below; a picked
     * Google Doc or uploaded file has already been extracted synchronously before this job was
     * ever dispatched (see IntakeImportService::import()), so it supplies $content directly and
     * there's nothing to fetch.
     */
    public function __construct(
        public readonly Document $document,
        public readonly ?string $recordingId = null,
        public readonly ?string $content = null,
    ) {}

    public function handle(MeetingTranscriptService $service): void
    {
        // Whether AI processing follows is decided purely by the document's own resolved
        // type, never by where the content came from (see DocumentTypeResolver — the same
        // rule every import source follows). A non-intake type — e.g. a recording picked with
        // "Import As: Meeting Notes" — is already-finished content the instant it's in hand,
        // exactly like a Google Doc/file picked as that same type: no AI step.
        $isIntake = $this->document->type === config('workflow.intake_key');
        $statusVerb = $isIntake ? 'Generating Meeting Notes...' : 'Importing...';

        // Same status text through every step of this job (rather than phase-specific text
        // like "Fetching transcript..."/"Saving transcript...") so the status line the user
        // lands on right after import reads as one continuous operation — ProcessDocumentAI::
        // handle() below keeps that same text going once it takes over for the intake branch's
        // actual note-generation step.
        event(new DocumentProcessingUpdate($this->document, $statusVerb, 15));

        if ($this->recordingId !== null) {
            $organization = $this->document->project->client->organization;

            if (! $organization?->meeting_provider) {
                Log::warning("ImportMeetingTranscript: no meeting provider for project [{$this->document->project_id}].");
                $this->document->update(['processed_at' => now()]);

                return;
            }

            $content = $service->fetchTranscript($organization, $this->recordingId);
        } else {
            $content = $this->content ?? '';
        }

        if (empty(trim($content))) {
            Log::warning("ImportMeetingTranscript: empty content for document [{$this->document->id}].");
            $this->document->update(['processed_at' => now()]);
            event(new DocumentProcessingUpdate($this->document, 'No transcript content found.', 100));

            return;
        }

        event(new DocumentProcessingUpdate($this->document, $statusVerb, 65));

        if (! $isIntake) {
            // Not a transcript — save as finished content immediately, exactly like a Google
            // Doc/file imported as this same type: no AI step to hand off to. A normal (not
            // quiet) update, unlike the intake branch below, so DocumentObserver::updated()
            // sees content actually change and dispatches embedding generation — matching what
            // a synchronously-created Google Doc/file import already gets via created().
            $this->document->update(['content' => $content, 'processed_at' => now()]);
            event(new DocumentProcessingUpdate($this->document, 'Import complete.', 100));

            return;
        }

        // Save content without triggering the observer (which would prematurely dispatch
        // GenerateDocumentEmbedding before AI processing has run).
        $this->document->content = $content;
        $this->document->processed_at = null;
        $this->document->saveQuietly();

        ProcessDocumentAI::dispatchUnlessProcessing($this->document);
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
