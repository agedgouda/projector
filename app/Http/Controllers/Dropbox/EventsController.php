<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use App\Jobs\ImportDropboxFile;
use App\Models\DropboxFolderBinding;
use App\Models\DropboxWorkspace;
use App\Services\Dropbox\DropboxApiClient;
use App\Services\Import\FileImportProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles Dropbox's webhook: a one-time GET verification handshake (answered directly, no
 * signature involved — see VerifyDropboxSignature's own docblock for why), and POST change
 * notifications (verified by that same middleware before this ever runs).
 *
 * A notification names only which Dropbox accounts have changes, not what changed — the actual
 * changed entries are fetched via files/list_folder/continue, using each connected workspace's
 * own stored cursor to pick up exactly where the last notification left off.
 */
class EventsController extends Controller
{
    public function handle(Request $request, DropboxApiClient $client): Response
    {
        if ($request->isMethod('get')) {
            return response((string) $request->query('challenge'))
                ->header('Content-Type', 'text/plain')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        $accountIds = $request->input('list_folder.accounts', []);

        if (! is_array($accountIds)) {
            return response()->noContent();
        }

        foreach ($accountIds as $accountId) {
            if (! is_string($accountId)) {
                continue;
            }

            $workspace = DropboxWorkspace::where('account_id', $accountId)->first();

            if ($workspace !== null) {
                $this->processChanges($workspace, $client);
            }
        }

        return response()->noContent();
    }

    private function processChanges(DropboxWorkspace $workspace, DropboxApiClient $client): void
    {
        try {
            $result = $workspace->cursor !== null
                ? $client->listFolderContinue($workspace, $workspace->cursor)
                : $client->listFolder($workspace, '');
        } catch (Throwable $e) {
            Log::warning('Dropbox\EventsController: failed to list folder changes', ['workspace_id' => $workspace->id, 'message' => $e->getMessage()]);

            return;
        }

        $entries = $result['entries'];
        $cursor = $result['cursor'];

        while ($result['has_more']) {
            try {
                $result = $client->listFolderContinue($workspace, $cursor);
            } catch (Throwable $e) {
                Log::warning('Dropbox\EventsController: failed to page through folder changes', ['workspace_id' => $workspace->id, 'message' => $e->getMessage()]);

                break;
            }

            $entries = [...$entries, ...$result['entries']];
            $cursor = $result['cursor'];
        }

        $workspace->update(['cursor' => $cursor]);

        $bindings = $workspace->folderBindings;

        foreach ($entries as $entry) {
            $this->maybeDispatchImport($entry, $bindings);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  \Illuminate\Support\Collection<int, DropboxFolderBinding>  $bindings
     */
    private function maybeDispatchImport(array $entry, \Illuminate\Support\Collection $bindings): void
    {
        if (($entry['.tag'] ?? null) !== 'file') {
            // Folders and deleted entries don't have anything to import.
            return;
        }

        $entryPath = $entry['path_lower'] ?? null;
        $filename = $entry['name'] ?? null;

        if (! is_string($entryPath) || ! is_string($filename)) {
            return;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! FileImportProcessor::isSupportedExtension($extension)) {
            return;
        }

        $binding = $bindings->first(
            fn (DropboxFolderBinding $binding) => str_starts_with($entryPath, strtolower($binding->folder_path).'/')
        );

        if ($binding === null) {
            return;
        }

        // The immediate subfolder under the bound folder, if the file is nested one level
        // deeper than the bound folder itself — e.g. bound folder "/intake", file at
        // "/intake/meeting-notes/standup.docx" has subfolder "meeting-notes", but a file
        // directly in "/intake" has none. Only the first path segment counts, matching
        // ForcedTypeMatcher's "one deliberate folder choice" model — a file nested further
        // still just isn't tagged this way.
        $relativePath = trim(substr($entryPath, strlen(strtolower($binding->folder_path))), '/');
        $segments = explode('/', $relativePath);
        $subfolderName = count($segments) > 1 ? $segments[0] : null;

        ImportDropboxFile::dispatch($binding->project, $binding->dropboxWorkspace, $entryPath, $filename, $subfolderName);
    }
}
