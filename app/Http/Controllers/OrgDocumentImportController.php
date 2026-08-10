<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Services\DocumentFileExtractorService;
use App\Services\Google\GoogleExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrgDocumentImportController extends Controller
{
    /**
     * Hands the frontend a short-lived, drive.file-scoped access token for the Google
     * Picker widget's setOAuthToken(). Mirrors DocumentImportController::googlePickerToken()
     * for the project side — same 428 + connect_url pattern when not connected.
     */
    public function googlePickerToken(Request $request, Organization $organization, GoogleExportService $service): JsonResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

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
     * Import a Google Doc (already picked via the Picker widget) as a status meeting.
     */
    public function importGoogleDoc(Request $request, Organization $organization, GoogleExportService $service): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

        $validated = $request->validate([
            'file_id' => 'required|string',
            'title' => 'required|string|max:255',
            'custom_prompt' => 'nullable|string',
        ]);

        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return back()->withErrors(['file_id' => 'Your Google connection has expired. Please try again.']);
        }

        $html = $service->fetchDocAsHtml($accessToken, $validated['file_id']);

        $this->createProcessedOrgDocument($organization, [
            'name' => $validated['title'],
            'content' => $html,
            'custom_prompt' => $validated['custom_prompt'] ?? null,
            'metadata' => ['import_source' => 'google_doc', 'google_file_id' => $validated['file_id']],
        ]);

        return back()->with('success', "Imported \"{$validated['title']}\"…");
    }

    /**
     * Import an uploaded .docx or .txt file as a status meeting.
     */
    public function importFile(Request $request, Organization $organization, DocumentFileExtractorService $extractor): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('create', [OrgDocument::class, $organization]);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:docx,txt', 'max:10240'],
            'custom_prompt' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $html = match ($extension) {
            'docx' => $extractor->extractDocxHtml($file->getRealPath()),
            'txt' => $extractor->extractTxtHtml($file->getRealPath()),
            default => abort(422, 'Unsupported file type.'),
        };

        $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $this->createProcessedOrgDocument($organization, [
            'name' => $title,
            'content' => $html,
            'custom_prompt' => $validated['custom_prompt'] ?? null,
            'metadata' => ['import_source' => 'file_upload', 'original_filename' => $file->getClientOriginalName()],
        ]);

        return back()->with('success', "Imported \"{$title}\"…");
    }

    /**
     * Creates a status-meeting OrgDocument with content already known synchronously (unlike
     * importFromRecording()/importRecording() on OrgDocumentController, which create an empty
     * placeholder and fill it in later via an async job) — so, matching OrgDocumentController::
     * store()'s own conditional for manually-typed content, this auto-dispatches extraction
     * immediately instead of waiting for a manual "Process" click.
     *
     * @param  array{name: string, content: string, custom_prompt: ?string, metadata: array<string, string>}  $attributes
     */
    private function createProcessedOrgDocument(Organization $organization, array $attributes): OrgDocument
    {
        $orgDocument = $organization->orgDocuments()->create([
            'type' => 'status_meeting',
            ...$attributes,
        ]);

        if (! empty($orgDocument->content)) {
            $orgDocument->updateQuietly([
                'metadata' => array_merge($orgDocument->metadata ?? [], [
                    'ai_draft' => ['status' => 'processing'],
                ]),
            ]);

            ProcessOrgDocumentAI::dispatch($orgDocument);
        }

        return $orgDocument;
    }
}
