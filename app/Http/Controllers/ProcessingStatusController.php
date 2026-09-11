<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OrgDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessingStatusController extends Controller
{
    /**
     * Single shared reconciliation check for every client-side AI-processing poller (see
     * useProcessingReconciler.ts) — replaces what used to be a separate router.reload() poll
     * per project/document/status-meeting page with one org-wide id lookup they each diff
     * their own tracked ids against.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = getPermissionsTeamId();

        abort_unless($orgId !== null, 403);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->organizations()->where('organizations.id', $orgId)->exists(), 403);

        // processed_at is only ever meaningful for document types that actually go through an
        // AI processing/embedding step (see DocumentObserver::created()/creating() and
        // GenerateDocumentEmbedding::handle()) — for everything else (e.g. a spreadsheet-
        // imported 'event' row with no description to embed, or the task_list_import/
        // event_list_import record itself, deliberately excluded from vectorization) it's just
        // never touched and sits null forever. Excluding those here keeps this query to rows
        // that are actually mid-run, not permanently-null-by-design ones that were never
        // "processing" in the first place.
        $processingDocumentIds = Document::whereNull('processed_at')
            ->whereNotIn('type', ['event', 'task_list_import', 'event_list_import'])
            ->whereHas('project.client', fn ($query) => $query->where('organization_id', $orgId))
            ->pluck('id');

        $processingOrgDocumentIds = OrgDocument::where('organization_id', $orgId)
            ->where('metadata->ai_draft->status', 'processing')
            ->pluck('id');

        return response()->json([
            'processing_document_ids' => $processingDocumentIds,
            'processing_org_document_ids' => $processingOrgDocumentIds,
        ]);
    }
}
