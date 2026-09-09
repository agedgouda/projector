<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\PendingImport;
use App\Services\DocumentFileExtractorService;
use App\Services\TaskListImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
     * linger in the queue.
     */
    public function destroy(PendingImport $pendingImport): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', [Document::class, $pendingImport->project]);

        $pendingImport->delete();

        if (request()->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back();
    }
}
