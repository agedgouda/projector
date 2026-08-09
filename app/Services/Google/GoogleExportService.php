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
     * Creates a native Google Sheet in the connected user's own Drive and writes the given
     * header + data rows to it in a single call.
     *
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     * @return array{id: string, url: string}
     */
    public function createSheet(string $accessToken, string $title, array $headerRow, array $dataRows): array
    {
        $createResponse = Http::withToken($accessToken)
            ->post('https://sheets.googleapis.com/v4/spreadsheets', [
                'properties' => ['title' => $title],
            ]);

        if ($createResponse->failed()) {
            throw new \RuntimeException('Failed to create Google Sheet: '.$createResponse->body());
        }

        $spreadsheetIdValue = $createResponse->json('spreadsheetId');
        $spreadsheetUrlValue = $createResponse->json('spreadsheetUrl');
        $spreadsheetId = is_string($spreadsheetIdValue) ? $spreadsheetIdValue : '';
        $spreadsheetUrl = is_string($spreadsheetUrlValue) ? $spreadsheetUrlValue : '';

        $writeResponse = Http::withToken($accessToken)
            ->put("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/A1?valueInputOption=RAW", [
                'values' => [$headerRow, ...$dataRows],
            ]);

        if ($writeResponse->failed()) {
            throw new \RuntimeException('Failed to write Google Sheet values: '.$writeResponse->body());
        }

        return ['id' => $spreadsheetId, 'url' => $spreadsheetUrl];
    }

    /**
     * Creates a native Google Doc in the connected user's own Drive, containing a table of
     * the given header + data rows.
     *
     * A brand-new Google Doc's table cells can't be addressed by index up front — the API
     * only reports each cell's insertion point after the (still-empty) table actually exists
     * in the document — so this makes three calls: create the table, re-fetch the document to
     * read the resulting cell positions, then fill every cell in a single batch. Cells are
     * filled last-to-first: inserting text shifts the index of everything after it, so working
     * backwards means each not-yet-filled cell's precomputed index is still valid when its turn
     * comes.
     *
     * @param  array<int, string>  $headerRow
     * @param  array<int, array<int, string>>  $dataRows
     * @return array{id: string, url: string}
     */
    public function createDoc(string $accessToken, string $title, array $headerRow, array $dataRows): array
    {
        $createResponse = Http::withToken($accessToken)
            ->post('https://docs.googleapis.com/v1/documents', [
                'title' => $title,
            ]);

        if ($createResponse->failed()) {
            throw new \RuntimeException('Failed to create Google Doc: '.$createResponse->body());
        }

        $documentIdValue = $createResponse->json('documentId');
        $documentId = is_string($documentIdValue) ? $documentIdValue : '';

        $rows = [$headerRow, ...$dataRows];

        $tableResponse = Http::withToken($accessToken)
            ->post("https://docs.googleapis.com/v1/documents/{$documentId}:batchUpdate", [
                'requests' => [[
                    'insertTable' => [
                        'rows' => count($rows),
                        'columns' => count($headerRow),
                        'location' => ['index' => 1],
                    ],
                ]],
            ]);

        if ($tableResponse->failed()) {
            throw new \RuntimeException('Failed to insert Google Doc table: '.$tableResponse->body());
        }

        $getResponse = Http::withToken($accessToken)
            ->get("https://docs.googleapis.com/v1/documents/{$documentId}");

        if ($getResponse->failed()) {
            throw new \RuntimeException('Failed to read back Google Doc structure: '.$getResponse->body());
        }

        $documentBody = $getResponse->json();
        $cellStartIndexes = $this->tableCellStartIndexes(is_array($documentBody) ? $documentBody : []);
        $values = array_merge(...$rows);

        $insertRequests = [];
        foreach (array_reverse($cellStartIndexes, true) as $i => $startIndex) {
            $value = $values[$i] ?? '';
            if ($value === '') {
                continue;
            }

            $insertRequests[] = [
                'insertText' => [
                    'location' => ['index' => $startIndex],
                    'text' => $value,
                ],
            ];
        }

        if (! empty($insertRequests)) {
            $fillResponse = Http::withToken($accessToken)
                ->post("https://docs.googleapis.com/v1/documents/{$documentId}:batchUpdate", [
                    'requests' => $insertRequests,
                ]);

            if ($fillResponse->failed()) {
                throw new \RuntimeException('Failed to fill Google Doc table: '.$fillResponse->body());
            }
        }

        return ['id' => $documentId, 'url' => "https://docs.google.com/document/d/{$documentId}/edit"];
    }

    /**
     * Walks a documents.get response to find the single table this class ever creates, and
     * returns the insertion index of each of its (empty) cells, in row-major reading order.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, int>
     */
    private function tableCellStartIndexes(array $document): array
    {
        $body = $document['body'] ?? null;
        $content = is_array($body) ? ($body['content'] ?? []) : [];
        $content = is_array($content) ? $content : [];

        $table = null;
        foreach ($content as $element) {
            if (is_array($element) && isset($element['table']) && is_array($element['table'])) {
                $table = $element['table'];
                break;
            }
        }

        if (! is_array($table)) {
            return [];
        }

        $tableRows = $table['tableRows'] ?? [];
        $tableRows = is_array($tableRows) ? $tableRows : [];

        $startIndexes = [];
        foreach ($tableRows as $row) {
            $tableCells = is_array($row) ? ($row['tableCells'] ?? []) : [];
            $tableCells = is_array($tableCells) ? $tableCells : [];

            foreach ($tableCells as $cell) {
                $content = is_array($cell) ? ($cell['content'] ?? []) : [];
                $paragraph = is_array($content) ? ($content[0] ?? null) : null;
                $startIndex = is_array($paragraph) ? ($paragraph['startIndex'] ?? 0) : 0;
                $startIndexes[] = is_numeric($startIndex) ? (int) $startIndex : 0;
            }
        }

        return $startIndexes;
    }
}
