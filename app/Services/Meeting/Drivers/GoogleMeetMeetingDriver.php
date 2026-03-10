<?php

namespace App\Services\Meeting\Drivers;

use App\Contracts\MeetingDriver;
use Illuminate\Support\Facades\Http;

/**
 * Google Meet meeting driver via Google Meet REST API.
 *
 * Requires in meeting_config: service_account_email, private_key, impersonate_email.
 * Uses a service account with domain-wide delegation (JWT bearer flow).
 *
 * Setup steps:
 *  1. Create a service account in Google Cloud Console.
 *  2. Enable domain-wide delegation on the service account.
 *  3. In Google Workspace Admin, grant the service account the scope:
 *       https://www.googleapis.com/auth/meetings.space.readonly
 *  4. Store the service account email, private key (PEM), and the
 *     Google Workspace user email to impersonate in meeting_config.
 */
class GoogleMeetMeetingDriver implements MeetingDriver
{
    private const SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';

    private function getAccessToken(array $config): string
    {
        $jwt = $this->buildJwt(
            $config['service_account_email'],
            $config['private_key'],
            $config['impersonate_email'],
        );

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Google Meet authentication failed: '.$response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Build a signed RS256 JWT for the service account JWT bearer flow.
     */
    private function buildJwt(string $serviceAccountEmail, string $privateKey, string $impersonateEmail): string
    {
        $now = time();

        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));

        $payload = $this->base64url(json_encode([
            'iss' => $serviceAccountEmail,
            'sub' => $impersonateEmail,
            'scope' => self::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$payload}";

        // Normalize escaped newlines from JSON service account files.
        $privateKey = str_replace('\n', "\n", $privateKey);

        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return "{$signingInput}.".$this->base64url($signature);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function listRecordings(array $config, \DateTimeInterface $since): array
    {
        $token = $this->getAccessToken($config);

        $response = Http::withToken($token)
            ->get('https://meet.googleapis.com/v2/conferenceRecords', [
                'filter' => 'start_time>="'.$since->format('Y-m-d\TH:i:s\Z').'"',
                'pageSize' => 50,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Google Meet recordings: '.$response->body());
        }

        $conferences = $response->json('conferenceRecords', []);

        // Only return conferences that have transcripts available
        return collect($conferences)
            ->filter(function ($conf) use ($token) {
                $transcripts = Http::withToken($token)
                    ->get("https://meet.googleapis.com/v2/{$conf['name']}/transcripts")
                    ->json('transcripts', []);

                return ! empty($transcripts);
            })
            ->map(fn ($conf) => [
                'id' => $conf['name'],
                'title' => 'Google Meet — '.($conf['startTime']
                    ? (new \DateTime($conf['startTime'], new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('America/Los_Angeles'))
                        ->format('n/j/Y g:ia')
                    : now()->setTimezone('America/Los_Angeles')->format('n/j/Y')),
                'started_at' => $conf['startTime'] ?? now()->toISOString(),
                'duration_minutes' => isset($conf['startTime'], $conf['endTime'])
                    ? (int) round((strtotime($conf['endTime']) - strtotime($conf['startTime'])) / 60)
                    : 0,
            ])
            ->values()
            ->all();
    }

    public function fetchTranscript(array $config, string $recordingId): string
    {
        $token = $this->getAccessToken($config);

        $transcriptsResponse = Http::withToken($token)
            ->get("https://meet.googleapis.com/v2/{$recordingId}/transcripts");

        if ($transcriptsResponse->failed()) {
            throw new \RuntimeException('Failed to fetch Google Meet transcripts: '.$transcriptsResponse->body());
        }

        $transcripts = $transcriptsResponse->json('transcripts', []);

        if (empty($transcripts)) {
            throw new \RuntimeException("No transcripts found for Google Meet recording [{$recordingId}].");
        }

        $entriesResponse = Http::withToken($token)
            ->get("https://meet.googleapis.com/v2/{$transcripts[0]['name']}/entries");

        if ($entriesResponse->failed()) {
            throw new \RuntimeException('Failed to fetch Google Meet transcript entries: '.$entriesResponse->body());
        }

        return collect($entriesResponse->json('transcriptEntries', []))
            ->map(fn ($entry) => trim($entry['text'] ?? ''))
            ->filter()
            ->implode("\n");
    }
}
