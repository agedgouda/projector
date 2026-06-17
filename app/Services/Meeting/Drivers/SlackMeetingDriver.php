<?php

namespace App\Services\Meeting\Drivers;

use App\Contracts\MeetingDriver;
use Illuminate\Support\Facades\Http;

/**
 * Slack meeting driver.
 *
 * Imports transcripts from Slack Clips — audio/video clips that Slack
 * automatically transcribes when shared in a channel or DM.
 *
 * Requires in meeting_config: bot_token (Slack Bot User OAuth Token, xoxb-...).
 *
 * Required Slack app scope:
 *   - files:read
 */
class SlackMeetingDriver implements MeetingDriver
{
    private const API_BASE = 'https://slack.com/api';

    public function listRecordings(array $config, \DateTimeInterface $since): array
    {
        $response = Http::withToken($config['bot_token'])
            ->get(self::API_BASE.'/files.list', [
                'ts_from' => $since->getTimestamp(),
                'types' => 'all',
                'count' => 100,
            ]);

        if ($response->failed() || ! $response->json('ok')) {
            throw new \RuntimeException('Failed to fetch Slack files: '.($response->json('error') ?? $response->body()));
        }

        $files = $response->json('files', []);

        return collect($files)
            ->filter(fn ($file) => ($file['transcription']['status'] ?? null) === 'complete')
            ->map(fn ($file) => [
                'id' => $file['id'],
                'title' => $file['title'] ?? 'Slack Clip',
                'started_at' => (new \DateTime('@'.($file['timestamp'] ?? time())))->format(\DateTimeInterface::ATOM),
                'duration_minutes' => isset($file['duration_ms']) ? (int) round($file['duration_ms'] / 60000) : 0,
            ])
            ->values()
            ->all();
    }

    public function fetchTranscript(array $config, string $recordingId): string
    {
        $response = Http::withToken($config['bot_token'])
            ->get(self::API_BASE.'/files.info', [
                'file' => $recordingId,
            ]);

        if ($response->failed() || ! $response->json('ok')) {
            throw new \RuntimeException('Failed to fetch Slack file info: '.($response->json('error') ?? $response->body()));
        }

        $file = $response->json('file', []);

        return $file['transcription']['preview']['content'] ?? '';
    }
}
