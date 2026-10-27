<?php

namespace App\Services\Meeting\Drivers;

use App\Contracts\MeetingDriver;
use App\Contracts\ProvidesRecordingAudio;
use App\Exceptions\RecordingUnavailableException;
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
 *  3. In Google Workspace Admin, grant the service account the scopes:
 *       https://www.googleapis.com/auth/meetings.space.readonly
 *       https://www.googleapis.com/auth/drive.meet.readonly (optional — only needed to
 *       transcribe meetings that were recorded without Meet's own transcript)
 *  4. Store the service account email, private key (PEM), and the
 *     Google Workspace user email to impersonate in meeting_config.
 *
 * Meet's conferenceRecords.list only returns meetings the impersonated user organized, and Meet
 * saves a meeting's recording to its organizer's Drive — so every recording listed here is in
 * the Drive of the user this driver already acts as.
 */
class GoogleMeetMeetingDriver implements MeetingDriver, ProvidesRecordingAudio
{
    private const SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';

    // Requested on its own token, only when actually downloading — an organization that never
    // added it to its domain-wide delegation keeps listing and importing transcripts normally,
    // since asking for an undelegated scope fails the whole token request.
    private const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.meet.readonly';

    public const MISSING_DRIVE_SCOPE_MESSAGE = 'Projector can\'t download this recording from Google Drive yet. A Google Workspace admin needs to add the https://www.googleapis.com/auth/drive.meet.readonly scope to Projector\'s service account under Security > API controls > Domain-wide delegation (see the Google Meet Setup Guide), then click Try Again.';

    private function getAccessToken(array $config, string $scope = self::SCOPE): string
    {
        $jwt = $this->buildJwt(
            $config['service_account_email'],
            $config['private_key'],
            $config['impersonate_email'],
            $scope,
        );

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->failed()) {
            if ($scope === self::DRIVE_SCOPE && in_array($response->json('error'), ['unauthorized_client', 'access_denied'], true)) {
                throw new RecordingUnavailableException(self::MISSING_DRIVE_SCOPE_MESSAGE);
            }

            throw new \RuntimeException('Google Meet authentication failed: '.$response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Build a signed RS256 JWT for the service account JWT bearer flow.
     */
    private function buildJwt(string $serviceAccountEmail, string $privateKey, string $impersonateEmail, string $scope): string
    {
        $now = time();

        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));

        $payload = $this->base64url(json_encode([
            'iss' => $serviceAccountEmail,
            'sub' => $impersonateEmail,
            'scope' => $scope,
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

        // A conference with Meet's own transcript is imported as text; one that was only
        // recorded is still listed (has_transcript false) and transcribed from its recording on
        // import. One with neither has nothing to import.
        return collect(is_array($conferences) ? $conferences : [])
            ->filter(fn ($conf): bool => is_array($conf) && is_string($conf['name'] ?? null))
            ->map(function (array $conf) use ($token): array {
                $conf['has_transcript'] = $this->transcriptsFor($token, $conf['name']) !== [];

                return $conf;
            })
            ->filter(fn (array $conf): bool => $conf['has_transcript'] || $this->generatedRecordingsFor($token, $conf['name']) !== [])
            ->map(fn ($conf) => [
                'id' => $conf['name'],
                'title' => 'Google Meet — '.($conf['startTime']
                    ? (new \DateTime($conf['startTime'], new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('America/Los_Angeles'))
                        ->format('m/d/Y g:ia')
                    : now()->setTimezone('America/Los_Angeles')->format('m/d/Y')),
                'started_at' => $conf['startTime'] ?? now()->toISOString(),
                'duration_minutes' => isset($conf['startTime'], $conf['endTime'])
                    ? (int) round((strtotime($conf['endTime']) - strtotime($conf['startTime'])) / 60)
                    : 0,
                'has_transcript' => $conf['has_transcript'],
            ])
            ->values()
            ->all();
    }

    public function hasTranscript(array $config, string $recordingId): bool
    {
        return $this->transcriptsFor($this->getAccessToken($config), $recordingId) !== [];
    }

    /**
     * When recording was stopped and restarted there are several files, and MP4s can't simply
     * be joined end to end — the longest one is used, which for the usual accidental
     * stop/start is the actual meeting.
     */
    public function downloadRecording(array $config, string $recordingId, string $destinationPath): void
    {
        $recordings = $this->generatedRecordingsFor($this->getAccessToken($config), $recordingId);

        usort($recordings, fn (array $a, array $b): int => $this->durationSeconds($b) <=> $this->durationSeconds($a));

        $destination = $recordings[0]['driveDestination'] ?? null;
        $fileId = is_array($destination) ? ($destination['file'] ?? null) : null;

        if (! is_string($fileId) || $fileId === '') {
            throw new RecordingUnavailableException('This meeting\'s recording file isn\'t available in Google Drive.');
        }

        $driveToken = $this->getAccessToken($config, self::DRIVE_SCOPE);

        $response = Http::withToken($driveToken)
            ->sink($destinationPath)
            ->timeout(3600)
            ->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
                'alt' => 'media',
                'supportsAllDrives' => 'true',
            ]);

        if ($response->failed()) {
            @unlink($destinationPath);

            if ($response->status() === 404) {
                throw new RecordingUnavailableException('This recording\'s file is no longer in Google Drive — it may have been deleted or moved.');
            }

            throw new \RuntimeException('Failed to download the Google Meet recording from Google Drive (HTTP '.$response->status().').');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transcriptsFor(string $token, string $conferenceName): array
    {
        $transcripts = Http::withToken($token)
            ->get("https://meet.googleapis.com/v2/{$conferenceName}/transcripts")
            ->json('transcripts', []);

        if (! is_array($transcripts)) {
            return [];
        }

        /** @var list<array<string, mixed>> $valid */
        $valid = array_values(array_filter($transcripts, 'is_array'));

        return $valid;
    }

    /**
     * Only recordings whose file actually exists in Drive yet — Meet reports a recording as
     * ENDED for a while after the meeting before its file is generated.
     *
     * @return list<array<string, mixed>>
     */
    private function generatedRecordingsFor(string $token, string $conferenceName): array
    {
        $recordings = Http::withToken($token)
            ->get("https://meet.googleapis.com/v2/{$conferenceName}/recordings")
            ->json('recordings', []);

        if (! is_array($recordings)) {
            return [];
        }

        /** @var list<array<string, mixed>> $generated */
        $generated = array_values(array_filter(
            $recordings,
            fn ($recording): bool => is_array($recording)
                && ($recording['state'] ?? null) === 'FILE_GENERATED'
                && is_array($recording['driveDestination'] ?? null)
                && is_string($recording['driveDestination']['file'] ?? null),
        ));

        return $generated;
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function durationSeconds(array $recording): int
    {
        $start = is_string($recording['startTime'] ?? null) ? strtotime($recording['startTime']) : false;
        $end = is_string($recording['endTime'] ?? null) ? strtotime($recording['endTime']) : false;

        return $start !== false && $end !== false ? $end - $start : 0;
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

        if (! is_array($transcripts) || empty($transcripts)) {
            throw new \RuntimeException("No transcripts found for Google Meet recording [{$recordingId}].");
        }

        // Stopping and restarting transcription mid-meeting splits it into multiple
        // separate transcript objects under the same conference record — fetch and
        // concatenate every segment, in chronological order, instead of only the first.
        usort($transcripts, function ($a, $b) {
            $aStart = is_array($a) && is_string($a['startTime'] ?? null) ? $a['startTime'] : '';
            $bStart = is_array($b) && is_string($b['startTime'] ?? null) ? $b['startTime'] : '';

            return $aStart <=> $bStart;
        });

        return collect($transcripts)
            ->map(function ($transcript) use ($token) {
                $transcriptName = is_array($transcript) && is_string($transcript['name'] ?? null) ? $transcript['name'] : '';

                $entriesResponse = Http::withToken($token)
                    ->get("https://meet.googleapis.com/v2/{$transcriptName}/entries");

                if ($entriesResponse->failed()) {
                    throw new \RuntimeException('Failed to fetch Google Meet transcript entries: '.$entriesResponse->body());
                }

                return collect($entriesResponse->json('transcriptEntries', []))
                    ->map(fn ($entry) => trim($entry['text'] ?? ''))
                    ->filter()
                    ->implode("\n");
            })
            ->filter()
            ->implode("\n");
    }
}
