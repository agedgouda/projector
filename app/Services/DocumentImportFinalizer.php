<?php

namespace App\Services;

use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Arr;

/**
 * The one place every intake-document import path (a browser/mobile-recorded audio file, a
 * picked provider recording, an imported Google Doc, an uploaded file, and whatever import
 * source is added next) converges once it has its own intake document: whether to pre-create a
 * blank Meeting Notes child, which document the user should actually be sent to, and what to
 * tell them while it's still generating. Every source calls finalize() here instead of
 * reimplementing that decision itself, so changing it changes it everywhere at once, and a new
 * import source gets the same behavior automatically just by calling it.
 */
class DocumentImportFinalizer
{
    public function finalize(Project $project, Document $document): ImportFinalizeResult
    {
        $target = $document;

        if ($this->isSingleOutput()) {
            // The user should never land on the raw transcript page — pre-create the Meeting
            // Notes document it's about to generate (blank for now) and send them there instead.
            // ProcessDocumentAI::handle() finds this same pre-existing row and fills it in
            // place once the AI call returns, so its id never changes.
            //
            // recording_source/recording_id are copied onto it from the parent (when present)
            // so isAsyncImportedDocument() — and the Documentation tab's unread dot it drives —
            // can attach to whichever document the user is actually redirected to, instead of
            // only ever the raw transcript most import sources no longer send anyone to
            // directly. processed_at is deliberately left null here (unlike the parent, which
            // sets it up front to suppress its own auto-dispatch) — it stays null until
            // ProcessDocumentAI actually fills this row in, which is exactly the "done
            // processing" signal the dot is gated on.
            $target = $project->documents()->create([
                'parent_id' => $document->id,
                'type' => config('workflow.action_items_key'),
                'name' => $document->name,
                'content' => '',
                'metadata' => Arr::only($document->metadata ?? [], ['recording_source', 'recording_id']),
            ]);
        }

        return new ImportFinalizeResult($target, $this->messageFor($document));
    }

    /**
     * Exposed separately from finalize() for a caller (e.g. TusUploadController) that already
     * ran finalize() indirectly via a service like RecordingIntakeService and just needs the
     * same message wording for a flash, without creating a second Meeting Notes child.
     */
    public function messageFor(Document $document): string
    {
        return "Importing \"{$document->name}\"…";
    }

    /**
     * Only safe to pre-create a Meeting Notes child when the "Transcript to Meeting Notes" AI
     * template is configured single_output — otherwise a transcript can produce zero, one, or
     * many documents, so there's no single stable id to create ahead of time.
     */
    private function isSingleOutput(): bool
    {
        $templateId = config('workflow.intake_to_action_items_ai_template_id');

        return is_int($templateId) && (bool) AiTemplate::find($templateId)?->single_output;
    }
}
