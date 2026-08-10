<?php

namespace App\Services\Google;

use App\Models\GoogleOauthToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class GoogleExportService
{
    /**
     * Returns a fresh access token for the user's connected Google account, refreshing it
     * first if it's expired. Returns null if the user isn't connected, or if Google reports
     * the refresh token was revoked (in which case the stored connection is also deleted, so
     * the caller doesn't keep retrying a connection the user has already revoked).
     */
    public function getValidAccessToken(User $user): ?string
    {
        $token = $user->googleOauthToken;

        if (! $token) {
            return null;
        }

        if (! $token->isExpired()) {
            return $token->access_token;
        }

        return $this->refreshAccessToken($token);
    }

    private function refreshAccessToken(GoogleOauthToken $token): ?string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]);

        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            $token->delete();

            return null;
        }

        if ($response->failed()) {
            throw new \RuntimeException('Failed to refresh Google access token: '.$response->body());
        }

        $expiresIn = $response->json('expires_in', 3600);

        $token->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds(is_numeric($expiresIn) ? (int) $expiresIn : 3600),
        ]);

        return $token->access_token;
    }

    /**
     * Whether the app itself (not any individual user) has everything configured for the
     * Google Picker widget — the OAuth client plus the separate browser-facing API key and
     * app ID Picker needs. Shared by every controller that decides whether to offer a
     * "pick a Google Doc" import option.
     */
    public function pickerConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.api_key'))
            && filled(config('services.google.app_id'));
    }

    /**
     * Creates a native Google Sheet in the connected user's own Drive from the given header +
     * data rows.
     *
     * Builds a CSV and uploads it via uploadAndConvert() rather than calling the Sheets API
     * (sheets.googleapis.com) directly — that's a separate API from Drive with its own scope
     * requirement (spreadsheets), which the app deliberately never requests (see
     * createDocFromHtml()'s docblock for the same reasoning applied to Docs). Drive's own
     * CSV-to-Sheet import conversion covers this without needing it.
     *
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     * @return array{id: string, url: string}
     */
    public function createSheet(string $accessToken, string $title, array $headerRow, array $dataRows): array
    {
        $id = $this->uploadAndConvert(
            $accessToken,
            $title,
            $this->tableCsv($headerRow, $dataRows),
            'text/csv',
            'application/vnd.google-apps.spreadsheet',
        );

        return ['id' => $id, 'url' => "https://docs.google.com/spreadsheets/d/{$id}/edit"];
    }

    /**
     * Creates a native Google Doc in the connected user's own Drive, containing a table of
     * the given header + data rows. Built as an HTML table and handed to createDocFromHtml()
     * — see that method's docblock for why (avoids the Docs API's own `documents` scope,
     * which the app deliberately never requests).
     *
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     * @return array{id: string, url: string}
     */
    public function createDoc(string $accessToken, string $title, array $headerRow, array $dataRows): array
    {
        return $this->createDocFromHtml($accessToken, $title, $this->tableHtml($headerRow, $dataRows));
    }

    /**
     * Creates a native Google Doc in the connected user's own Drive from a chunk of HTML,
     * preserving basic rich-text formatting (bold, headings, lists, tables, etc.).
     *
     * Rather than hand-building Docs API (docs.googleapis.com) structural edits, this uses
     * Drive's own HTML-to-Google-Doc import conversion: uploading content as text/html with
     * the target mimeType set to a Google Doc makes Drive do the HTML parsing and formatting
     * conversion itself. This is also the only reason the app's OAuth scope can stay limited
     * to drive.file — the Docs and Sheets APIs are entirely separate from Drive and each
     * require their own scope (`documents`, `spreadsheets`) that a drive.file-only token
     * doesn't carry, which is exactly what broke this in production (Google returns 403
     * ACCESS_TOKEN_SCOPE_INSUFFICIENT for docs.googleapis.com calls). Letting Drive perform
     * the conversion sidesteps needing those broader, more sensitive scopes at all.
     *
     * @return array{id: string, url: string}
     */
    public function createDocFromHtml(string $accessToken, string $title, string $html): array
    {
        $id = $this->uploadAndConvert($accessToken, $title, $html, 'text/html', 'application/vnd.google-apps.document');

        return ['id' => $id, 'url' => "https://docs.google.com/document/d/{$id}/edit"];
    }

    /**
     * Uploads arbitrary source content to Drive with a target Google Workspace mimeType,
     * letting Drive's own importer convert it (HTML -> Doc, CSV -> Sheet, etc.) — the shared
     * mechanism behind createDoc(), createSheet(), and createDocFromHtml(). Kept scoped to
     * drive.file: the resulting file is one this app itself just created.
     */
    private function uploadAndConvert(string $accessToken, string $title, string $content, string $sourceContentType, string $targetMimeType): string
    {
        $boundary = 'projector-'.bin2hex(random_bytes(16));
        $metadata = json_encode(['name' => $title, 'mimeType' => $targetMimeType], JSON_UNESCAPED_SLASHES);

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            ."{$metadata}\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$sourceContentType}; charset=UTF-8\r\n\r\n"
            ."{$content}\r\n"
            ."--{$boundary}--";

        $response = Http::withToken($accessToken)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to create Google file: '.$response->body());
        }

        $idValue = $response->json('id');

        return is_string($idValue) ? $idValue : '';
    }

    /**
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     */
    private function tableHtml(array $headerRow, array $dataRows): string
    {
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);

        $headCells = implode('', array_map(fn ($v) => '<th>'.$escape((string) $v).'</th>', $headerRow));
        $bodyRows = implode('', array_map(
            fn ($row) => '<tr>'.implode('', array_map(fn ($v) => '<td>'.$escape((string) $v).'</td>', $row)).'</tr>',
            $dataRows,
        ));

        return "<table><tr>{$headCells}</tr>{$bodyRows}</table>";
    }

    /**
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     */
    private function tableCsv(array $headerRow, array $dataRows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Failed to open a temporary stream for CSV generation.');
        }

        fputcsv($stream, $headerRow);
        foreach ($dataRows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * Fetches a Google Doc's content as Drive's own HTML export — the read-direction mirror
     * of createDocFromHtml() above, which uses the same "let Google convert" approach in
     * reverse (Drive does the Docs-to-HTML formatting conversion, not us).
     */
    public function fetchDocAsHtml(string $accessToken, string $fileId): string
    {
        $response = Http::withToken($accessToken)
            ->get("https://www.googleapis.com/drive/v3/files/{$fileId}/export", ['mimeType' => 'text/html']);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Google Doc content: '.$response->body());
        }

        return $response->body();
    }
}
