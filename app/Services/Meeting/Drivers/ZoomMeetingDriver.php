<?php

namespace App\Services\Meeting\Drivers;

use App\Contracts\MeetingDriver;
use Illuminate\Support\Facades\Http;

/**
 * Zoom Server-to-Server OAuth meeting driver.
 *
 * Requires in meeting_config: account_id, client_id, client_secret.
 * Uses the Zoom Server-to-Server OAuth 2.0 flow (no user interaction).
 */
class ZoomMeetingDriver implements MeetingDriver
{
    private function getAccessToken(array $config): string
    {
        $response = Http::withBasicAuth($config['client_id'], $config['client_secret'])
            ->asForm()
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $config['account_id'],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Zoom authentication failed: '.$response->body());
        }

        return $response->json('access_token');
    }

    public function listRecordings(array $config, \DateTimeInterface $since): array
    {
        $token = $this->getAccessToken($config);

        $response = Http::withToken($token)
            ->get('https://api.zoom.us/v2/users/me/recordings', [
                'from' => $since->format('Y-m-d'),
                'to' => now()->format('Y-m-d'),
                'page_size' => 50,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Zoom recordings: '.$response->body());
        }

        $meetings = $response->json('meetings', []);

        return collect($meetings)
            ->filter(fn ($m) => collect($m['recording_files'] ?? [])
                ->contains('file_type', 'TRANSCRIPT'))
            ->map(fn ($m) => [
                'id' => $m['uuid'],
                'title' => $m['topic'] ?? 'Zoom Meeting',
                'started_at' => $m['start_time'],
                'duration_minutes' => (int) ($m['duration'] ?? 0),
            ])
            ->values()
            ->all();
    }

    public function fetchTranscript(array $config, string $recordingId): string
    {
        $token = $this->getAccessToken($config);

        // recordingId for Zoom is the meeting UUID; we need the VTT download URL
        $response = Http::withToken($token)
            ->get("https://api.zoom.us/v2/meetings/{$recordingId}/recordings");

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Zoom recording details: '.$response->body());
        }

        $transcriptFile = collect($response->json('recording_files', []))
            ->firstWhere('file_type', 'TRANSCRIPT');

        if (! $transcriptFile) {
            throw new \RuntimeException("No transcript file found for Zoom recording [{$recordingId}].");
        }

        $downloadUrl = $transcriptFile['download_url'].'?access_token='.$token;

        $content = Http::get($downloadUrl)->body();

        return $this->vttToPlainText($content);
    }

    /**
     * Convert WebVTT caption format to plain readable text.
     */
    private function vttToPlainText(string $vtt): string
    {
        $lines = preg_split('/\r?\n/', $vtt);
        $text = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip header, cue IDs, timestamps, and blank lines
            if ($line === 'WEBVTT' || $line === '' || str_contains($line, '-->') || is_numeric($line)) {
                continue;
            }

            $text[] = $line;
        }

        return implode("\n", $text);
    }
}
