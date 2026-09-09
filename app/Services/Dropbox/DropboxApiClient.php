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
     * Resolves a human-typed folder path (e.g. "/Client Intake") to Dropbox's own stable folder
     * id — lets OrganizationDropboxFoldersController accept just a path from the binding form
     * rather than requiring whoever's binding a folder to already know its Dropbox id. Throws if
     * the path doesn't exist or isn't a folder, so the caller can turn that into a validation
     * error instead of silently binding to nothing.
     *
     * @return array{folder_id: string, folder_path: string}
     */
    public function resolveFolder(DropboxWorkspace $workspace, string $path): array
    {
        $response = Http::withToken($this->ensureFreshToken($workspace))
            ->post(self::API_BASE.'/files/get_metadata', ['path' => $path]);

        if ($response->failed() || $response->json('.tag') !== 'folder') {
            throw new \RuntimeException("Dropbox path \"{$path}\" is not a folder in this account: ".$response->body());
        }

        $folderId = $response->json('id');
        $resolvedPath = $response->json('path_display');

        if (! is_string($folderId) || ! is_string($resolvedPath)) {
            throw new \RuntimeException('Dropbox files/get_metadata returned an unexpected shape.');
        }

        return ['folder_id' => $folderId, 'folder_path' => $resolvedPath];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, cursor: string, has_more: bool}
     */
    public function listFolder(DropboxWorkspace $workspace, string $path): array
    {
        $response = Http::withToken($this->ensureFreshToken($workspace))
            ->post(self::API_BASE.'/files/list_folder', [
                'path' => $path,
                'recursive' => true,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dropbox files/list_folder failed: '.$response->body());
        }

        /** @var array{entries: list<array<string, mixed>>, cursor: string, has_more: bool} */
        return $response->json();
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
