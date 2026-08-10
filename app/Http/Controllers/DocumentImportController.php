<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DocumentFileExtractorService;
use App\Services\Google\GoogleExportService;
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
    public function importGoogleDoc(Request $request, Project $project, GoogleExportService $service): RedirectResponse
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'file_id' => 'required|string',
            'title' => 'required|string|max:255',
            'custom_prompt' => 'nullable|string',
        ]);

        // Re-check rather than trust the token from googlePickerToken() above — it could have
        // expired or been revoked in the seconds the Picker was open.
        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return back()->withErrors(['file_id' => 'Your Google connection has expired. Please try again.']);
        }

        $html = $service->fetchDocAsHtml($accessToken, $validated['file_id']);

        // No placeholder/two-phase dance needed — unlike ImportMeetingTranscript (async,
        // because Zoom/Teams/Meet fetches are slow), fetching a Doc's HTML export is a single
        // fast request, so content is already known by the time the document is created.
        // DocumentObserver::created() dispatches ProcessDocumentAI automatically once it sees
        // an intake-type document with content and no processed_at.
        $project->documents()->create([
            'type' => config('workflow.intake_key'),
            'name' => $validated['title'],
            'content' => $html,
            'custom_prompt' => $validated['custom_prompt'] ?? null,
            'metadata' => ['import_source' => 'google_doc', 'google_file_id' => $validated['file_id']],
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
        $project->documents()->create([
            'type' => config('workflow.intake_key'),
            'name' => $title,
            'content' => $html,
            'custom_prompt' => $validated['custom_prompt'] ?? null,
            'metadata' => ['import_source' => 'file_upload', 'original_filename' => $file->getClientOriginalName()],
        ]);

        return back()->with('success', "Imported \"{$title}\"…");
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
