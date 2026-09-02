<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DocumentFileExtractorService;
use App\Services\DocumentTypeResolver;
use App\Services\Google\GoogleExportService;
use App\Services\IntakeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function importGoogleDoc(Request $request, Project $project, GoogleExportService $service, IntakeImportService $importer, DocumentTypeResolver $typeResolver): RedirectResponse
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

        return $this->createImportedDocument($project, $validated['title'], $html, $validated['custom_prompt'] ?? null, $validated['type'] ?? null, $validated['new_type_label'] ?? null, [
            'import_source' => 'google_doc',
            'google_file_id' => $validated['file_id'],
        ], $importer, $typeResolver);
    }

    /**
     * Import an uploaded .docx or .txt file as an intake document.
     */
    public function importFile(Request $request, Project $project, DocumentFileExtractorService $extractor, IntakeImportService $importer, DocumentTypeResolver $typeResolver): RedirectResponse
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
        // request, so there's no later job that would need the file to still exist — a
        // Transcription-type import still hands that already-extracted HTML off to
        // ImportMeetingTranscript (via createImportedDocument() below) purely to keep every
        // import source on the one shared pipeline, not because there's anything left to fetch.
        return $this->createImportedDocument($project, $title, $html, $validated['custom_prompt'] ?? null, $validated['type'] ?? null, $validated['new_type_label'] ?? null, [
            'import_source' => 'file_upload',
            'original_filename' => $file->getClientOriginalName(),
        ], $importer, $typeResolver);
    }

    /**
     * Creates the imported document as whichever type the user picked. The workflow's own
     * intake type (the default) hands off to IntakeImportService — the exact same pipeline the
     * Transcripts tab's recording picker uses — so a Google Doc or file picked as "Transcription"
     * gets the same AI processing and same redirect to the eventual Meeting Notes document that
     * a picked recording gets, regardless of the fact that its content was already extracted
     * synchronously above. Any other type — an existing catalog type or a brand new one the user
     * is naming for the first time right here — is already-finished content (e.g. notes someone
     * already cleaned up): no AI call, `processed_at` stamped immediately (its content is
     * already fully known, unlike a recording of this same type — see MeetingTranscriptController
     * ::store()'s own non-intake branch), and `custom_prompt` — meaningless with no AI step to
     * apply it to — dropped. Either way the user is sent straight to the new document's own page
     * rather than staying on the modal/tab they imported from.
     *
     * @param  array<string, string>  $metadata
     */
    private function createImportedDocument(Project $project, string $title, string $html, ?string $customPrompt, ?string $type, ?string $newTypeLabel, array $metadata, IntakeImportService $importer, DocumentTypeResolver $typeResolver): RedirectResponse
    {
        $resolved = $typeResolver->resolve($project, $type, $newTypeLabel);

        if ($resolved['type'] === config('workflow.intake_key')) {
            return $importer->import($project, $title, null, $html, $customPrompt, $metadata);
        }

        $document = $project->documents()->create([
            'type' => $resolved['type'],
            'name' => $title,
            'content' => $html,
            'custom_prompt' => null,
            'processed_at' => now(),
            'metadata' => $metadata,
        ]);

        return redirect()
            ->route('projects.documents.show', [$project, $document])
            ->with('success', "Imported \"{$title}\"…");
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
