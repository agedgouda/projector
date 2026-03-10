<?php

namespace App\Services\Meeting\Drivers;

use App\Contracts\MeetingDriver;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Teams meeting driver via Microsoft Graph API.
 *
 * Requires in meeting_config: tenant_id, client_id, client_secret.
 * Uses client credentials flow (app-only permissions).
 *
 * Required Graph API permissions (application):
 *   - OnlineMeetings.Read.All
 *   - OnlineMeetingTranscript.Read.All
 */
class TeamsMeetingDriver implements MeetingDriver
{
    private function getAccessToken(array $config): string
    {
        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/token",
            [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
            ]
        );

        if ($response->failed()) {
            throw new \RuntimeException('Teams authentication failed: '.$response->body());
        }

        return $response->json('access_token');
    }

    public function listRecordings(array $config, \DateTimeInterface $since): array
    {
        $token = $this->getAccessToken($config);

        // Fetch all online meeting recordings since the given date
        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/communications/callRecords', [
                '$filter' => "startDateTime ge {$since->format(\DateTimeInterface::ATOM)}",
                '$select' => 'id,joinWebUrl,startDateTime,endDateTime,participants',
                '$top' => 50,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Teams recordings: '.$response->body());
        }

        $records = $response->json('value', []);

        // Only return calls that have transcripts
        return collect($records)
            ->filter(function ($record) use ($token) {
                $transcripts = Http::withToken($token)
                    ->get("https://graph.microsoft.com/v1.0/communications/callRecords/{$record['id']}/microsoft.graph.callRecords.getDirectRoutingCalls")
                    ->json('value', []);

                return ! empty($transcripts);
            })
            ->map(fn ($record) => [
                'id' => $record['id'],
                'title' => 'Teams Meeting — '.substr($record['startDateTime'], 0, 10),
                'started_at' => $record['startDateTime'],
                'duration_minutes' => (int) round(
                    (strtotime($record['endDateTime']) - strtotime($record['startDateTime'])) / 60
                ),
            ])
            ->values()
            ->all();
    }

    public function fetchTranscript(array $config, string $recordingId): string
    {
        $token = $this->getAccessToken($config);

        // List transcripts for this meeting
        $transcriptsResponse = Http::withToken($token)
            ->get("https://graph.microsoft.com/v1.0/communications/callRecords/{$recordingId}/transcripts");

        if ($transcriptsResponse->failed()) {
            throw new \RuntimeException('Failed to fetch Teams transcripts: '.$transcriptsResponse->body());
        }

        $transcripts = $transcriptsResponse->json('value', []);

        if (empty($transcripts)) {
            throw new \RuntimeException("No transcripts found for Teams meeting [{$recordingId}].");
        }

        $transcriptId = $transcripts[0]['id'];

        $contentResponse = Http::withToken($token)
            ->get(
                "https://graph.microsoft.com/v1.0/communications/callRecords/{$recordingId}/transcripts/{$transcriptId}/content",
                ['$format' => 'text/vtt']
            );

        if ($contentResponse->failed()) {
            throw new \RuntimeException('Failed to download Teams transcript content: '.$contentResponse->body());
        }

        return $this->vttToPlainText($contentResponse->body());
    }

    private function vttToPlainText(string $vtt): string
    {
        $lines = preg_split('/\r?\n/', $vtt);
        $text = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'WEBVTT' || $line === '' || str_contains($line, '-->') || is_numeric($line)) {
                continue;
            }

            $text[] = $line;
        }

        return implode("\n", $text);
    }
}
