<?php

namespace App\Services;

use App\Jobs\ImportMeetingTranscript;
use App\Models\AiTemplate;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class IntakeImportService
{
    /**
     * Creates an intake document from any source — a picked meeting recording, a picked
     * Google Doc, or an uploaded file — and routes it through the one pipeline the
     * Transcripts tab itself has always used: ImportMeetingTranscript fills in the content
     * (fetching it from the meeting provider when $recordingId is given, or using $content
     * directly when it was already extracted synchronously), then hands off to
     * ProcessDocumentAI. Every source ends up redirected to the same place a picked
     * recording always has — straight to the eventual Meeting Notes document, pre-created as
     * a placeholder when the AI template is configured single_output, so the user watches it
     * generate live instead of staying on the page they imported from.
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

        // The user should never land on the raw transcript page — pre-create the Meeting
        // Notes document it's about to generate (blank for now) and send them straight there
        // instead. Only safe when the "Transcript to Meeting Notes" AI template is configured
        // single_output — otherwise a transcript can produce zero, one, or many documents, so
        // there's no single stable id to create ahead of time (falls back to the transcript
        // page in that case, same as before). ProcessDocumentAI::handle() fills this same row
        // in, in place, once the AI call returns (see its own comment).
        $templateId = config('workflow.intake_to_action_items_ai_template_id');
        $isSingleOutput = is_int($templateId) && (bool) AiTemplate::find($templateId)?->single_output;

        if ($isSingleOutput) {
            $meetingNotes = $project->documents()->create([
                'parent_id' => $document->id,
                'type' => config('workflow.action_items_key'),
                'name' => $title,
                'content' => '',
            ]);

            return redirect()
                ->route('projects.documents.show', [$project, $meetingNotes])
                ->with('success', "Importing \"{$title}\"…");
        }

        return redirect()
            ->route('projects.documents.show', [$project, $document])
            ->with('success', "Importing \"{$title}\"…");
    }
}
