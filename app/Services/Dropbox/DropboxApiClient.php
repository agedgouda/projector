<?php

namespace App\Services\Dropbox;

use App\Models\DropboxWorkspace;
use Illuminate\Support\Facades\Http;

/**
 * Centralizes every Dropbox API v2 call this app makes, and — the one thing every one of them
 * needs — keeps a workspace's access_token fresh first. Dropbox access tokens are short-lived
 * (unlike Slack's bot token), so ensureFreshToken() is checked proactively before each call
 * rather than discovering staleness via a failed request.
 */
class DropboxApiClient
{
    private const API_BASE = 'https://api.dropboxapi.com/2';

    private const CONTENT_BASE = 'https://content.dropboxapi.com/2';

    public function ensureFreshToken(DropboxWorkspace $workspace): string
    {
        if ($workspace->access_token_expires_at === null || $workspace->access_token_expires_at->isFuture()) {
            return $workspace->access_token;
        }

        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $workspace->refresh_token,
            'client_id' => config('services.dropbox.client_id'),
            'client_secret' => config('services.dropbox.client_secret'),
        ]);

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            throw new \RuntimeException('Failed to refresh Dropbox access token: '.$response->body());
        }

        $expiresIn = $response->json('expires_in');

        $workspace->update([
            'access_token' => $accessToken,
            // Dropbox access tokens are documented as lasting 4 hours — used as a fallback only
            // if expires_in is ever missing from the response, not as the normal case.
            'access_token_expires_at' => now()->addSeconds(is_int($expiresIn) ? $expiresIn : 14400),
        ]);

        return $accessToken;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, cursor: string, has_more: bool}
     */
    public function listFolder(DropboxWorkspace $workspace, string $path, bool $recursive = true): array
    {
        $response = Http::withToken($this->ensureFreshToken($workspace))
            ->post(self::API_BASE.'/files/list_folder', [
                'path' => $path,
                'recursive' => $recursive,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dropbox files/list_folder failed: '.$response->body());
        }

        /** @var array{entries: list<array<string, mixed>>, cursor: string, has_more: bool} */
        return $response->json();
    }

    /**
     * Every top-level folder in the connected account, for the folder-binding dropdown —
     * OrganizationDropboxFoldersController::store() trusts the id + path the frontend submits
     * directly from this list rather than re-resolving a typed path (as it used to; see the
     * removed resolveFolder(), which asked a human to guess an exact path and then quietly
     * always rejected it — see this method's git history for why). Not recursive, and doesn't
     * walk into subfolders — only what's directly under the account root.
     *
     * @return list<array{id: string, path: string}>
     */
    public function listTopLevelFolders(DropboxWorkspace $workspace): array
    {
        $folders = [];
        $page = $this->listFolder($workspace, '', recursive: false);

        while (true) {
            foreach ($page['entries'] as $entry) {
                if (($entry['.tag'] ?? null) === 'folder' && is_string($entry['id'] ?? null) && is_string($entry['path_display'] ?? null)) {
                    $folders[] = ['id' => $entry['id'], 'path' => $entry['path_display']];
                }
            }

            if (! $page['has_more']) {
                break;
            }

            $page = $this->listFolderContinue($workspace, $page['cursor']);
        }

        return $folders;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, cursor: string, has_more: bool}
     */
    public function listFolderContinue(DropboxWorkspace $workspace, string $cursor): array
    {
        $response = Http::withToken($this->ensureFreshToken($workspace))
            ->post(self::API_BASE.'/files/list_folder/continue', [
                'cursor' => $cursor,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dropbox files/list_folder/continue failed: '.$response->body());
        }

        /** @var array{entries: list<array<string, mixed>>, cursor: string, has_more: bool} */
        return $response->json();
    }

    /**
     * A content-transfer endpoint, not a normal RPC one — Dropbox wants the file path passed as
     * a JSON-encoded Dropbox-API-Arg header rather than a request body, and returns the raw file
     * bytes as the response body.
     */
    public function download(DropboxWorkspace $workspace, string $path): string
    {
        $response = Http::withToken($this->ensureFreshToken($workspace))
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $path])])
            ->post(self::CONTENT_BASE.'/files/download');

        if ($response->failed()) {
            throw new \RuntimeException('Dropbox files/download failed: '.$response->body());
        }

        return $response->body();
    }
}
