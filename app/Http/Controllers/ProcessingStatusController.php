<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OrgDocument;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessingStatusController extends Controller
{
    /**
     * Single shared reconciliation check for every client-side AI-processing poller (see
     * useProcessingReconciler.ts) — replaces what used to be a separate router.reload() poll
     * per project/document/status-meeting page with one lookup they each diff their own
     * tracked ids against.
     *
     * Scoped by what the user can actually see across every organization they belong to, not
     * by a single "current org" resolved from getPermissionsTeamId() — this route carries no
     * {project}/{client} route param, so SetOrganizationContext can only resolve that via a
     * `last_org_id` cookie fallback, which can legitimately go stale (multi-org accounts,
     * after switching orgs) and would otherwise 403 a background poll whose entire job is to
     * recover from exactly this kind of missed update. Every consumer only ever diffs the
     * returned ids against a small set it's already tracking, so returning "everything visible
     * across every org" instead of guessing one specific org is always safe, never incorrect.
     *
     * Deliberately Project::visibleTo($user, $orgId) per org rather than Document::visibleTo()
     * — the latter requires the user to be explicitly attached to a project's *client*, which
     * an org-admin/project-lead usually isn't (they see every project in their org through
     * their role instead); using it here would silently drop an org-admin's own documents from
     * this poll, trading one "stuck processing" bug for another.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user !== null, 401);

        $projectIds = $user->organizations->flatMap(
            fn ($organization) => Project::visibleTo($user, $organization->id)->pluck('id')
        )->unique();

        // processed_at is only ever meaningful for document types that actually go through an
        // AI processing/embedding step (see DocumentObserver::created()/creating() and
        // GenerateDocumentEmbedding::handle()) — for everything else (e.g. a spreadsheet-
        // imported 'event' row with no description to embed, or the task_list_import/
        // event_list_import record itself, deliberately excluded from vectorization) it's just
        // never touched and sits null forever. Excluding those here keeps this query to rows
        // that are actually mid-run, not permanently-null-by-design ones that were never
        // "processing" in the first place.
        $processingDocumentIds = Document::whereIn('project_id', $projectIds)
            ->whereNull('processed_at')
            ->whereNotIn('type', ['event', 'task_list_import', 'event_list_import'])
            ->pluck('id');

        $processingOrgDocumentIds = OrgDocument::whereIn('organization_id', $user->organizations->pluck('id'))
            ->where('metadata->ai_draft->status', 'processing')
            ->pluck('id');

        return response()->json([
            'processing_document_ids' => $processingDocumentIds,
            'processing_org_document_ids' => $processingOrgDocumentIds,
        ]);
    }
}
