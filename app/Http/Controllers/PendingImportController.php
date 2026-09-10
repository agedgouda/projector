<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ImportedFile;
use App\Models\PendingImport;
use App\Models\SlackChannelBinding;
use App\Services\DocumentFileExtractorService;
use App\Services\Import\ImportNotifier;
use App\Services\TaskListImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * A pending import is a file an import source (Slack, Dropbox, ...) downloaded but couldn't
 * import automatically — either a spreadsheet it couldn't confidently classify, a spreadsheet
 * with a column mapping this project hasn't confirmed before, or a Word document (which always
 * needs a human to classify, unless a #tag/subfolder forced a type directly) — parked here (see
 * PendingImport) instead of failing outright or guessing, so a human can resolve it from the
 * Import Wizard landing page.
 */
class PendingImportController extends Controller
{
    /**
     * Re-derives the same data a fresh manual upload would produce, from the file already
     * stored when this was queued — so the frontend can open the same ImportTransformationModal
     * it already uses either way, just skipping the "choose a file" step. Shape depends on
     * source_type: a spreadsheet re-parses via TaskListImportService::analyze() (matching
     * TaskListImportController::analyze()'s own response); a document re-extracts its text via
     * DocumentFileExtractorService (matching what ImportTaskListOptions.vue's client-side
     * file.text() would produce for a manually-picked plain-text "smart" import).
     */
    public function show(PendingImport $pendingImport, TaskListImportService $importService, DocumentFileExtractorService $extractor): JsonResponse
    {
        Gate::authorize('create', [Document::class, $pendingImport->project]);

        $media = $pendingImport->getFirstMedia('file');
        abort_if($media === null, 404);

        if ($pendingImport->source_type === 'text') {
            $text = trim(strip_tags($extractor->extractDocxHtml($media->getPath())));

            return response()->json([
                'source_mode' => 'text',
                'text' => $text,
                'original_filename' => $pendingImport->original_filename,
                'project_id' => $pendingImport->project_id,
                'document_type_catalog' => $pendingImport->project->documentTypeCatalog()->values(),
            ]);
        }

        $file = new UploadedFile($media->getPath(), $media->file_name, $media->mime_type, null, true);

        return response()->json([
            'source_mode' => 'spreadsheet',
            ...$importService->analyze($file),
            'original_filename' => $pendingImport->original_filename,
            'project_id' => $pendingImport->project_id,
        ]);
    }

    /**
     * Dismisses a pending import — used both for "not needed, discard it" and, by the frontend,
     * right after ImportTransformationModal successfully applies it, so a resolved item doesn't
     * linger in the queue. Only the former is an actual cancellation worth telling anyone about;
     * the frontend marks that case with `discarded=1` on the request (see Import/Wizard.vue's
     * dismissPendingImport() vs. handleImported(), which calls this same endpoint silently after
     * a successful apply) — without that distinction, a successful import would get the same
     * "canceled by user" message this sends for a real discard.
     */
    public function destroy(Request $request, PendingImport $pendingImport): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', [Document::class, $pendingImport->project]);

        if ($request->boolean('discarded')) {
            $this->notifyCanceled($pendingImport);
            $this->forgetImportedFile($pendingImport);
        }

        $pendingImport->delete();

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back();
    }

    /**
     * Best-effort — an import with no known uploader (shouldn't normally happen; every queueing
     * path stamps uploaded_by_user_id) just skips notifying rather than failing the discard.
     * Reuses the exact same ImportNotifier every other import message goes through: an in-channel
     * Slack reply if this project currently has a bound Slack channel (the file may well have
     * come from a different channel/source originally, but a project only realistically has one
     * bound channel in practice), otherwise the usual Slack DM-or-email fallback.
     */
    private function notifyCanceled(PendingImport $pendingImport): void
    {
        $attributedTo = $pendingImport->uploadedBy;

        if ($attributedTo === null) {
            return;
        }

        $project = $pendingImport->project;
        $url = route('import.index', ['org' => $project->organization_id]);
        $message = "Import canceled by user. Click here to restart: {$url}";

        $slackChannelReply = null;
        $binding = SlackChannelBinding::where('project_id', $project->id)->with('slackWorkspace')->first();

        if ($binding !== null) {
            $slackChannelReply = [
                'bot_token' => $binding->slackWorkspace->bot_access_token,
                'channel_id' => $binding->channel_id,
            ];
        }

        app(ImportNotifier::class)->notify($attributedTo, $message, $project, $slackChannelReply);
    }

    /**
     * A discarded pending import was never actually imported, so the ImportedFile dedup row
     * FileImportProcessor::recordImportedFile() wrote when this was first queued (see
     * queueForValidation()) needs to go too — otherwise re-uploading the exact same file (the
     * Slack "canceled" notification's own "click here to restart" instruction) is silently
     * blocked by the "already been imported" dedup check even though nothing was ever actually
     * imported. Re-hashes the file already attached to this pending import rather than needing
     * a stored content_hash column, so this must run before the pending import (and its media)
     * is deleted.
     */
    private function forgetImportedFile(PendingImport $pendingImport): void
    {
        $media = $pendingImport->getFirstMedia('file');
        if ($media === null) {
            return;
        }

        $contentHash = hash_file('sha256', $media->getPath());
        if ($contentHash === false) {
            return;
        }

        ImportedFile::where('project_id', $pendingImport->project_id)
            ->where('content_hash', $contentHash)
            ->delete();
    }
}
