<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Rules\ValidKanbanColumn;
use App\Services\Google\GoogleExportService;
use App\Services\VectorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Show the form for creating a new document.
     */
    public function create(Request $request, Project $project)
    {
        Gate::authorize('create', [Document::class, $project]);

        return inertia('Documents/Create', [
            'project' => $project->load(['client.organization.users', 'client.organization.invitations']),
            'documentTypeCatalog' => $project->documentTypeCatalog()->values(),
            'redirectUrl' => $request->query('redirect'),
            'defaultType' => $request->query('type'),
        ]);
    }

    /**
     * Store a newly created document in storage.
     */
    public function store(StoreDocumentRequest $request, Project $project)
    {
        Gate::authorize('create', [Document::class, $project]);

        // The document only cares about validated data
        $document = $project->documents()->create(array_merge(
            $request->validated(),
            ['creator_id' => $request->user()->id]
        ));

        $definition = $project->documentTypeCatalog()->get($document->type);
        $isTask = $definition instanceof \App\Models\DocumentTypeDefinition && $definition->is_task;

        // "Save and New" (Documents/Create.vue) sends `redirect` back to its own create form
        // for any document type, not just tasks — honored here regardless of type, falling
        // back to each type's normal post-create destination when it's absent. Restricted to
        // same-origin relative paths (never a bare "//host" either) since it's an unvalidated
        // query param and this endpoint redirects to it directly.
        $default = $isTask
            ? route('projects.show', $project).'?tab=tasks'
            : route('projects.show', $project).'?tab=hierarchy';

        $redirectParam = $request->query('redirect');
        $target = (is_string($redirectParam) && str_starts_with($redirectParam, '/') && ! str_starts_with($redirectParam, '//'))
            ? $redirectParam
            : $default;

        return redirect()->to($target)
            ->with('success', 'Document created successfully.');
    }

    /**
     * Display the specified document.
     */
    public function show(Project $project, Document $document)
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        // Every other board in this task's subproject family (see Project::familyProjectIds())
        // — what the "Move to" / "Also show on" pickers in DocumentSidebar.vue choose from.
        // Empty for a project with no parent and no siblings, in which case the frontend just
        // doesn't render that UI at all — nothing to move to or link.
        $boardOptions = Project::whereIn('id', array_diff($project->familyProjectIds(), [$project->id]))
            ->orderBy('name')
            ->get(['id', 'name']);

        return inertia('Documents/Show', [
            'project' => $project->load(['client.organization.users', 'client.organization.invitations', 'kanbanColumns']),
            'documentTypeCatalog' => $project->documentTypeCatalog()->values(),
            'boardOptions' => $boardOptions,
            'item' => $document->load([
                'assignee', 'pendingAssignee', 'creator', 'editor', 'comments.user',
                'parent.parent.parent', 'lastAiTemplate:id,name', 'linkedProjects:id,name',
                // Explicit order (matching the project tree's own 'documents' => ...->latest()
                // eager load in ProjectController::show()) — without it, Postgres has no
                // guaranteed row order across repeated queries, so the "Generated Tasks" list
                // here (DocumentContent.vue) could visually reshuffle after every single field
                // edit's reload, since each edit re-runs this exact query. Whichever row a user
                // was about to click could silently become a different task by the time the
                // click landed. `created_at` alone isn't fine-grained enough to break ties
                // between children created in the same batch (e.g. several tasks generated from
                // one AI run land in the same second) — `id` is a second sort key because
                // Document's HasUuids trait generates time-ordered UUIDs, giving a tiebreaker
                // with far finer precision than the timestamp column.
                'children' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id')->with(['assignee', 'pendingAssignee']),
            ])->loadExists(['lockedNextWorkflowStep', 'children']),
        ]);
    }

    /**
     * Export the specified document as a branded PDF, suitable for sending to a client.
     */
    public function exportPdf(Project $project, Document $document): \Illuminate\Http\Response
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $project->loadMissing('client.organization');

        $catalogEntry = $project->documentTypeCatalog()->get($document->type);
        $typeLabel = $catalogEntry instanceof \App\Models\DocumentTypeDefinition ? $catalogEntry->label : $document->type;

        $organization = $project->client?->organization;

        $pdf = Pdf::loadView('pdfs.document', [
            'document' => $document,
            'project' => $project,
            'client' => $project->client,
            'typeLabel' => $typeLabel,
            'logoPath' => $project->getFirstMedia('logo')?->getPath('preview'),
            'headerImagePath' => $organization?->getFirstMedia('pdf_header')?->getPath('preview'),
            'footerImagePath' => $organization?->getFirstMedia('pdf_footer')?->getPath('preview'),
        ]);

        $filename = is_string($document->name) ? Str::slug($document->name) : 'document';

        return $pdf->download($filename.'.pdf');
    }

    /**
     * Export the specified document as a Word (.docx) file, suitable for sending to a client.
     */
    public function exportWord(Project $project, Document $document): StreamedResponse
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $catalogEntry = $project->documentTypeCatalog()->get($document->type);
        $typeLabel = $catalogEntry instanceof \App\Models\DocumentTypeDefinition ? $catalogEntry->label : $document->type;

        $documentName = is_string($document->name) ? $document->name : 'Untitled';

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        $section->addText($documentName, ['bold' => true, 'size' => 20, 'color' => '0F172A']);
        $section->addText($typeLabel, ['size' => 9, 'bold' => true, 'allCaps' => true, 'color' => '6366F1']);
        $section->addTextBreak();

        PhpWordHtml::addHtml($section, $this->normalizeHtmlForWord($document->content), false, false);

        $filename = Str::slug($documentName);

        return response()->streamDownload(function () use ($phpWord) {
            $phpWord->save('php://output', 'Word2007');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Export the specified document as a native Google Doc, landing directly in the exporting
     * user's own Drive — same title + type label + rich-text content layout as exportWord()
     * above. Returns 428 with a connect_url if the user hasn't connected a Google account yet.
     */
    public function exportGoogleDoc(Request $request, Project $project, Document $document, GoogleExportService $service): JsonResponse
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return response()->json([
                'message' => 'Connect your Google account to export to Google Docs.',
                'connect_url' => route('integrations.google.connect'),
            ], 428);
        }

        $catalogEntry = $project->documentTypeCatalog()->get($document->type);
        $typeLabel = $catalogEntry instanceof \App\Models\DocumentTypeDefinition ? $catalogEntry->label : $document->type;

        $documentName = is_string($document->name) ? $document->name : 'Untitled';
        $content = trim((string) $document->content) !== '' ? $document->content : '<p>No content provided.</p>';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            .'<h1 style="font-size:20pt;color:#0F172A;">'.e($documentName).'</h1>'
            .'<p style="font-size:9pt;font-weight:bold;color:#6366F1;text-transform:uppercase;">'.e($typeLabel).'</p>'
            .$content
            .'</body></html>';

        $doc = $service->createDocFromHtml($accessToken, Str::slug($documentName), $html);

        return response()->json($doc);
    }

    /**
     * PhpWord's HTML importer parses its input as strict XML, but this app's rich-text
     * editor produces ordinary (not always self-closing) HTML — e.g. bare `<br>` tags —
     * which fails XML parsing. Round-tripping through DOMDocument's lenient HTML parser
     * first normalizes it into well-formed XML PhpWord can consume.
     */
    private function normalizeHtmlForWord(string $html): string
    {
        if (trim($html) === '') {
            return '<p>No content provided.</p>';
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '<p>No content provided.</p>';
        }

        $normalized = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $normalized .= $dom->saveXML($child);
        }

        return $normalized !== '' ? $normalized : '<p>No content provided.</p>';
    }

    /**
     * Update the specified document in storage.
     */
    public function update(StoreDocumentRequest $request, Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        // Track who is editing the document, and when its content last changed (as opposed to
        // updateAttributes()'s quick sidebar edits) so the frontend can offer to reprocess only
        // when there's actually new content the last AI run hasn't seen yet.
        $document->update(array_merge(
            $request->validated(),
            ['editor_id' => $request->user()->id, 'content_updated_at' => now()]
        ));

        return back()->with('success', 'Document updated.');
    }

    /**
     * Update only task attributes (assignee, status, due date).
     * Allowed by any org member, unlike the full update which requires project access.
     */
    public function updateAttributes(Request $request, Project $project, Document $document)
    {
        Gate::authorize('updateAttributes', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $otherValidated = $request->validate([
            'task_status' => ['nullable', 'string', new ValidKanbanColumn($project->id)],
            'priority' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'external_due_at' => ['nullable', 'date'],
        ]);

        $assigneeData = $request->has('assignee_id')
            ? $this->resolveAssignee($request->input('assignee_id'), $request, $project)
            : [];

        $document->update(array_merge($assigneeData, $otherValidated, ['editor_id' => $request->user()->id]));

        return back()->with('success', 'Task updated.');
    }

    /**
     * Move a task to a different board within its subproject family (its top-level project
     * plus that project's direct children — see Project::familyProjectIds()). The target
     * board's Kanban columns must match the task's current board key-for-key
     * (Project::hasMatchingKanbanColumns()) — required so the task's single task_status
     * value stays meaningful after the move, rather than landing on a board with no column
     * for its current status.
     */
    public function move(Request $request, Project $project, Document $document)
    {
        Gate::authorize('move', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'string'],
        ]);

        $targetId = $validated['project_id'];

        if ($targetId === $project->id) {
            throw ValidationException::withMessages(['project_id' => 'This task is already on that board.']);
        }

        if (! in_array($targetId, $project->familyProjectIds(), true)) {
            throw ValidationException::withMessages(['project_id' => 'That board is outside this task\'s project family.']);
        }

        $target = Project::findOrFail($targetId);

        if (! $project->hasMatchingKanbanColumns($target)) {
            throw ValidationException::withMessages(['project_id' => 'That board\'s statuses don\'t match this one\'s, so this task can\'t move there.']);
        }

        // A move makes the target the new home — it can't also be a "linked" extra board
        // for a document that already lives there natively.
        $document->linkedProjects()->detach($target->id);

        $document->update(['project_id' => $target->id, 'editor_id' => $request->user()->id]);

        // Deliberately not back() (unlike updateAttributes() etc.) — the page the user was
        // just on is keyed to the *old* project in its URL (/projects/{old}/documents/{doc}),
        // which 404s the moment project_id changes (see the ownership guard at the top of
        // show()/this method). Sending them to the document's own new URL is what actually
        // keeps the page they're looking at valid after a move.
        return redirect()
            ->route('projects.documents.show', ['project' => $target->id, 'document' => $document->id])
            ->with('success', 'Task moved.');
    }

    /**
     * Set the complete list of boards (beyond this task's home board) it's also shown on,
     * within its subproject family — see move() for the same family/matching-columns rules,
     * which apply here identically.
     */
    public function updateBoards(Request $request, Project $project, Document $document)
    {
        Gate::authorize('manageBoards', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'project_ids' => ['present', 'array'],
            'project_ids.*' => ['string'],
        ]);

        $familyIds = $project->familyProjectIds();
        $targetIds = array_values(array_unique($validated['project_ids']));

        foreach ($targetIds as $targetId) {
            if ($targetId === $project->id) {
                throw ValidationException::withMessages(['project_ids' => 'This task\'s own board can\'t also be an additional board.']);
            }

            if (! in_array($targetId, $familyIds, true)) {
                throw ValidationException::withMessages(['project_ids' => 'That board is outside this task\'s project family.']);
            }
        }

        if ($targetIds !== []) {
            $targets = Project::whereIn('id', $targetIds)->get()->keyBy('id');

            foreach ($targetIds as $targetId) {
                if (! $project->hasMatchingKanbanColumns($targets[$targetId])) {
                    throw ValidationException::withMessages(['project_ids' => 'That board\'s statuses don\'t match this one\'s, so this task can\'t be shown there.']);
                }
            }
        }

        $document->linkedProjects()->sync($targetIds);

        return back()->with('success', 'Boards updated.');
    }

    /**
     * Resolve an assignee_id input value (user ID or "inv:{id}" prefix) into DB column data.
     *
     * @return array{assignee_id: int|null, pending_assignee_invitation_id: int|null}
     */
    private function resolveAssignee(mixed $rawAssignee, Request $request, Project $project): array
    {
        if ($rawAssignee === null) {
            return ['assignee_id' => null, 'pending_assignee_invitation_id' => null];
        }

        if (is_string($rawAssignee) && str_starts_with($rawAssignee, 'inv:')) {
            $invitationId = (int) substr($rawAssignee, 4);
            $orgId = $project->client->organization_id;

            abort_unless(
                OrganizationInvitation::where('id', $invitationId)
                    ->where('organization_id', $orgId)
                    ->exists(),
                422,
                'Invalid invitation.'
            );

            return ['assignee_id' => null, 'pending_assignee_invitation_id' => $invitationId];
        }

        $request->validate(['assignee_id' => ['required', 'exists:users,id']]);

        return ['assignee_id' => (int) $rawAssignee, 'pending_assignee_invitation_id' => null];
    }

    /**
     * Search document context via Vector Service.
     */
    public function search(Request $request, Project $project, VectorService $vectorService)
    {
        Gate::authorize('view', $project);

        $queryText = $request->input('query');
        if (! $queryText) {
            return back();
        }

        $results = $vectorService->searchContext(
            $project,
            $queryText,
            $request->user()
        );

        return inertia('Projects/Show', [
            'project' => $project->loadFullPipeline(),
            'searchResults' => $results,
        ]);
    }

    /**
     * Restart AI processing for a document.
     */
    public function reprocess(Request $request, Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        $orgId = $project->client?->organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        if ($org && ($block = \App\Services\MembershipGuard::check($org, 'ai_docs'))) {
            return $block;
        }

        $validated = $request->validate([
            'one_off_instructions' => 'nullable|string',
        ]);

        // ProjectAiService::process() only auto-resolves a transition for an intake document or
        // one locked to a protocol — anything else has no single, unambiguous next step of its
        // own (see its final else-branch) unless it's already been run through one before, in
        // which case re-running that exact same template is the only sensible meaning of
        // "reprocess" for it (see Document::lastAiTemplate()).
        $overrideStep = null;
        if ($document->type !== config('workflow.intake_key')
            && ! $document->locked_project_type_id
            && $document->last_ai_template_id
            && $document->last_output_key) {
            $overrideStep = [
                'to_key' => $document->last_output_key,
                'ai_template_id' => $document->last_ai_template_id,
                'single_output' => false,
                'project_type_id' => null,
            ];
        }

        if (! \App\Jobs\ProcessDocumentAI::dispatchUnlessProcessing($document, $overrideStep, $validated['one_off_instructions'] ?? null)) {
            return response()->json(['message' => 'This document is already being processed.'], 409);
        }

        $document->update(['processed_at' => null]);

        return response()->json(['message' => 'AI analysis restarted.']);
    }

    /**
     * Run a specific, user-chosen transition on a document, replacing any children it previously
     * produced. Two ways to call this, matching the either/or processing choice:
     *  (a) protocol-driven: pass to_key + ai_template_id + project_type_id, resolved client-side
     *      from a chosen protocol's own workflow_steps row for this document's type — locks the
     *      resulting document's whole downstream lineage to that protocol, so further processing
     *      auto-continues via that protocol with no further choice offered;
     *  (b) direct: pass only ai_template_id — to_key comes from the template's own output_key
     *      (falling back to a slug of its name if that's unset), never from workflow_steps, and
     *      nothing gets locked.
     */
    public function transition(Request $request, Project $project, Document $document)
    {
        Gate::authorize('update', $document);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'to_key' => ['sometimes', 'string', 'max:255'],
            'ai_template_id' => ['required', 'integer', 'exists:ai_templates,id'],
            'single_output' => ['sometimes', 'boolean'],
            'project_type_id' => ['sometimes', 'nullable', 'uuid', 'exists:project_types,id'],
        ]);

        $orgId = $project->client?->organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        if ($org && ($block = \App\Services\MembershipGuard::check($org, 'ai_docs'))) {
            return $block;
        }

        $aiTemplateId = $validated['ai_template_id'];
        if (! is_int($aiTemplateId)) {
            abort(422, 'Invalid ai_template_id.');
        }

        $toKey = $validated['to_key'] ?? null;
        if (! is_string($toKey) || $toKey === '') {
            // Direct pick, no protocol involved: the template's own output_key is
            // authoritative here. Never consult workflow_steps/project_type for this —
            // a template's output type must not depend on which protocols happen to
            // reference it.
            $template = \App\Models\AiTemplate::findOrFail($aiTemplateId);
            $toKey = $template->output_key ?: \Illuminate\Support\Str::slug($template->name, '_');
        }

        $dispatched = \App\Jobs\ProcessDocumentAI::dispatchUnlessProcessing($document, [
            'to_key' => $toKey,
            'ai_template_id' => $aiTemplateId,
            'single_output' => $validated['single_output'] ?? false,
            'project_type_id' => $validated['project_type_id'] ?? null,
        ]);

        if (! $dispatched) {
            return response()->json(['message' => 'This document is already being processed.'], 409);
        }

        $document->update(['processed_at' => null]);

        return response()->json(['message' => 'Transition started.']);
    }

    /**
     * Options for the either/or processing picker:
     *  - protocolOptions: protocols visible to this org that define their own next step from this
     *    document's current type (path a — run that protocol's own recipe);
     *  - aiTemplates: every workflow-capable AI template (path b — run any template directly).
     */
    public function transitionOptions(Project $project, Document $document)
    {
        Gate::authorize('view', $project);

        if ($document->project_id !== $project->id) {
            abort(404);
        }

        $orgId = $project->client?->organization_id;

        $protocolOptions = \App\Models\ProjectType::where(function ($q) use ($orgId) {
            $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
        })
            ->whereHas('workflowSteps', fn ($q) => $q->where('from_key', $document->type)->whereNotNull('ai_template_id'))
            ->with(['workflowSteps' => fn ($q) => $q->where('from_key', $document->type)->whereNotNull('ai_template_id')])
            ->orderBy('name')
            ->get()
            ->map(function ($projectType) {
                $step = $projectType->workflowSteps->first();

                return [
                    'projectTypeId' => $projectType->id,
                    'name' => $projectType->name,
                    'toKey' => $step?->to_key,
                    'aiTemplateId' => $step?->ai_template_id,
                    'singleOutput' => $step?->single_output,
                ];
            })
            ->filter(fn (array $option) => $option['toKey'] !== null && $option['aiTemplateId'] !== null)
            ->values();

        // The universal Notes -> Action Items template runs automatically for every intake
        // document (see ProjectAiService::process()) and is never a manual choice.
        $aiTemplates = \App\Models\AiTemplate::where('type', 'workflow')
            ->where('id', '!=', config('workflow.intake_to_action_items_ai_template_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'protocolOptions' => $protocolOptions,
            'aiTemplates' => $aiTemplates,
        ]);
    }

    /**
     * Remove the specified document from storage.
     */
    public function destroy(Project $project, Document $document)
    {
        Gate::authorize('delete', $document);

        $document->delete();

        return redirect()->route('projects.show', $project)
            ->with('success', 'Document deleted successfully');
    }
}
