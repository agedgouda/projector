<?php

namespace App\Http\Controllers;

use App\Models\DocumentTypeDefinition;
use App\Models\Project;
use App\Services\DocumentFileExtractorService;
use App\Services\Google\GoogleExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DocumentImportController extends Controller
{
    /**
     * Hands the frontend a short-lived, drive.file-scoped access token for the Google
     * Picker widget's setOAuthToken(). Returns 428 with a connect_url if the user hasn't
     * connected a Google account yet, matching the export flows' own 428 pattern.
     */
    public function googlePickerToken(Request $request, Project $project, GoogleExportService $service): JsonResponse
    {
        $this->authorizeManage($request, $project);

        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return response()->json([
                'message' => 'Connect your Google account to import a Google Doc.',
                'connect_url' => route('integrations.google.connect'),
            ], 428);
        }

        return response()->json(['access_token' => $accessToken]);
    }

    /**
     * Import a Google Doc (already picked via the Picker widget) as an intake document.
     */
    public function importGoogleDoc(Request $request, Project $project, GoogleExportService $service): RedirectResponse
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'file_id' => 'required|string',
            'title' => 'required|string|max:255',
            'custom_prompt' => 'nullable|string',
            'type' => 'nullable|string',
            'new_type_label' => 'nullable|string|max:100',
        ]);

        // Re-check rather than trust the token from googlePickerToken() above — it could have
        // expired or been revoked in the seconds the Picker was open.
        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return back()->withErrors(['file_id' => 'Your Google connection has expired. Please try again.']);
        }

        $html = $service->fetchDocAsHtml($accessToken, $validated['file_id']);

        $this->createImportedDocument($project, $validated['title'], $html, $validated['custom_prompt'] ?? null, $validated['type'] ?? null, $validated['new_type_label'] ?? null, [
            'import_source' => 'google_doc',
            'google_file_id' => $validated['file_id'],
        ]);

        return back()->with('success', "Imported \"{$validated['title']}\"…");
    }

    /**
     * Import an uploaded .docx or .txt file as an intake document.
     */
    public function importFile(Request $request, Project $project, DocumentFileExtractorService $extractor): RedirectResponse
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:docx,txt', 'max:10240'],
            'custom_prompt' => 'nullable|string',
            'type' => 'nullable|string',
            'new_type_label' => 'nullable|string|max:100',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $html = match ($extension) {
            'docx' => $extractor->extractDocxHtml($file->getRealPath()),
            'txt' => $extractor->extractTxtHtml($file->getRealPath()),
            default => abort(422, 'Unsupported file type.'),
        };

        $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // The uploaded file itself is never persisted — read from its temp upload path,
        // converted to HTML, discarded. Extraction happens synchronously in this same
        // request, so there's no later job that would need the file to still exist.
        $this->createImportedDocument($project, $title, $html, $validated['custom_prompt'] ?? null, $validated['type'] ?? null, $validated['new_type_label'] ?? null, [
            'import_source' => 'file_upload',
            'original_filename' => $file->getClientOriginalName(),
        ]);

        return back()->with('success', "Imported \"{$title}\"…");
    }

    /**
     * Creates the imported document as whichever type the user picked — the workflow's own
     * intake type (the default; content is assumed raw and DocumentObserver::created() will
     * dispatch the universal Notes -> Action Items step automatically), an existing catalog
     * type (already-finished content, e.g. notes someone already cleaned up — no AI call at
     * all), or a brand new type the user is naming for the first time right here. Only the
     * intake type ever triggers AI processing; every other type — including a freshly created
     * one — skips it, so `processed_at` is stamped immediately (nothing left to wait on) and
     * `custom_prompt` — meaningless with no AI step to apply it to — is dropped.
     *
     * @param  array<string, string>  $metadata
     */
    private function createImportedDocument(Project $project, string $title, string $html, ?string $customPrompt, ?string $type, ?string $newTypeLabel, array $metadata): void
    {
        $resolved = $this->resolveDocumentType($project, $type, $newTypeLabel);
        $skipProcessing = $resolved['type'] !== config('workflow.intake_key');

        $project->documents()->create([
            'type' => $resolved['type'],
            'name' => $title,
            'content' => $html,
            'custom_prompt' => $skipProcessing ? null : $customPrompt,
            'processed_at' => $skipProcessing ? now() : null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Resolves the picker's choice to a real, valid type key — never trusting the frontend's
     * `type` string directly, since it's user-editable request data. A new label creates (or,
     * if the same label was already used by an earlier import, reuses) an org-scoped
     * DocumentTypeDefinition; the label a picked recording never sees (Transcripts are always
     * raw) is intentionally excluded from what a new type here can shadow.
     *
     * @return array{type: string}
     */
    private function resolveDocumentType(Project $project, ?string $type, ?string $newTypeLabel): array
    {
        $organizationId = $project->client?->organization_id;
        $intakeKey = config('workflow.intake_key');
        abort_unless(is_string($intakeKey), Response::HTTP_INTERNAL_SERVER_ERROR, 'workflow.intake_key is misconfigured.');

        if (filled($newTypeLabel)) {
            $label = trim($newTypeLabel);
            $key = Str::slug($label, '_');

            abort_if($key === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid type name.');

            $existingMaxOrder = DocumentTypeDefinition::where('organization_id', $organizationId)->max('order');
            $maxOrder = is_int($existingMaxOrder) ? $existingMaxOrder : 0;

            $definition = DocumentTypeDefinition::firstOrCreate(
                ['organization_id' => $organizationId, 'key' => $key],
                [
                    'label' => $label,
                    'is_task' => false,
                    'order' => $maxOrder + 1,
                ]
            );

            return ['type' => $definition->key];
        }

        // Matches the frontend's own source of truth (see visibleDocumentTypeKeys() in
        // resources/js/lib/documentTypes.ts): any type already used by a document in this
        // project is valid here, not just what's in the org's curated document_schema —
        // that catalog and "what this project actually has" are two different lists (a type
        // can easily exist on real documents — e.g. one this same endpoint created earlier via
        // new_type_label — without ever being added to it). The intake type is always valid
        // too, even for a project with none yet, so a brand new project isn't stuck with only
        // "Other". Same "documents, not tasks or events" scope as the Documentation tab's own
        // tree (see useDocumentTree.ts) — this endpoint only ever creates documents for that tab.
        $catalog = DocumentTypeDefinition::catalogForOrganization($organizationId);
        $isTaskType = function (string $key) use ($catalog): bool {
            $definition = $catalog->get($key);

            return $definition !== null && $definition->is_task;
        };

        $usedTypes = $project->documents()
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->reject(fn (string $key) => $key === 'event' || $isTaskType($key))
            ->push($intakeKey);

        abort_unless(is_string($type) && $usedTypes->contains($type), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid document type.');

        return ['type' => $type];
    }

    private function authorizeManage(Request $request, Project $project): void
    {
        $user = $request->user();
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($project->client->organization_id);

        $orgRole = $user->roleInOrganization($project->client->organization_id);
        $canManage = $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead']);

        abort_if(! $canManage, Response::HTTP_FORBIDDEN);
    }
}
