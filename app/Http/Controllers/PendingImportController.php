<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\SlackPendingImport;
use App\Services\TaskListImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * A pending import is a spreadsheet ImportSlackFile downloaded from Slack but couldn't
 * confidently classify as tasks or events on its own — parked here (see SlackPendingImport)
 * instead of failing outright, so a human can resolve it from the Import Wizard landing page.
 */
class PendingImportController extends Controller
{
    /**
     * Re-parses the stored file exactly like TaskListImportController::analyze() does for a
     * fresh upload — same response shape — so the frontend can open the same
     * ImportTransformationModal it already uses for a manually-picked file, just skipping the
     * "choose a file" step since this one's already on disk.
     */
    public function show(SlackPendingImport $pendingImport, TaskListImportService $importService): JsonResponse
    {
        Gate::authorize('create', [Document::class, $pendingImport->project]);

        $media = $pendingImport->getFirstMedia('file');
        abort_if($media === null, 404);

        $file = new UploadedFile($media->getPath(), $media->file_name, $media->mime_type, null, true);

        return response()->json([
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
    public function destroy(SlackPendingImport $pendingImport): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', [Document::class, $pendingImport->project]);

        $pendingImport->delete();

        if (request()->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back();
    }
}
