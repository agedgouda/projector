<?php

namespace App\Services;

use App\Jobs\ImportMeetingTranscript;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class IntakeImportService
{
    public function __construct(private readonly DocumentImportFinalizer $finalizer) {}

    /**
     * Creates an intake document from any source — a picked meeting recording, a picked
     * Google Doc, or an uploaded file — and routes it through the one pipeline the
     * Transcripts tab itself has always used: ImportMeetingTranscript fills in the content
     * (fetching it from the meeting provider when $recordingId is given, or using $content
     * directly when it was already extracted synchronously), then hands off to
     * ProcessDocumentAI. DocumentImportFinalizer decides where every source ends up redirected
     * — straight to the eventual Meeting Notes document, pre-created as a placeholder when the
     * AI template is configured single_output, so the user watches it generate live instead of
     * staying on the page they imported from.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function import(
        Project $project,
        string $title,
        ?string $recordingId,
        ?string $content,
        ?string $customPrompt,
        array $metadata,
    ): RedirectResponse {
        // Create a placeholder document immediately so the UI can track progress. Use
        // processed_at = now() temporarily to prevent the DocumentObserver from dispatching
        // ProcessDocumentAI before the content above has actually been filled in.
        $document = $project->documents()->create([
            'type' => config('workflow.intake_key'),
            'name' => $title,
            'content' => '',
            'processed_at' => now(),
            'metadata' => $metadata,
            'custom_prompt' => $customPrompt,
        ]);

        ImportMeetingTranscript::dispatch($document, $recordingId, $content);

        $result = $this->finalizer->finalize($project, $document);

        return redirect()
            ->route('projects.documents.show', [$project, $result->target])
            ->with('success', $result->message);
    }
}
