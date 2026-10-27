<?php

namespace App\Services;

use App\Contracts\MeetingDriver;
use App\Contracts\ProvidesRecordingAudio;
use App\Models\Organization;
use App\Services\Meeting\Drivers\GoogleMeetMeetingDriver;
use App\Services\Meeting\Drivers\SlackMeetingDriver;
use App\Services\Meeting\Drivers\TeamsMeetingDriver;
use App\Services\Meeting\Drivers\ZoomMeetingDriver;

class MeetingTranscriptService
{
    /**
     * Resolve the correct driver for the organization's meeting provider.
     */
    public function driver(Organization $organization): MeetingDriver
    {
        $provider = $organization->meeting_provider;

        return match ($provider) {
            'zoom' => new ZoomMeetingDriver,
            'teams' => new TeamsMeetingDriver,
            'google_meet' => new GoogleMeetMeetingDriver,
            'slack' => new SlackMeetingDriver,
            default => throw new \InvalidArgumentException(
                "Meeting provider [{$provider}] is not supported."
            ),
        };
    }

    /**
     * List recordings available for import.
     *
     * @return array<int, array{id: string, title: string, started_at: string, duration_minutes: int, has_transcript?: bool}>
     */
    public function listRecordings(Organization $organization, \DateTimeInterface $since): array
    {
        $config = $organization->meeting_config ?? [];

        return $this->driver($organization)->listRecordings($config, $since);
    }

    /**
     * Fetch the plain-text transcript for a specific recording.
     */
    public function fetchTranscript(Organization $organization, string $recordingId): string
    {
        $config = $organization->meeting_config ?? [];

        return $this->driver($organization)->fetchTranscript($config, $recordingId);
    }

    /**
     * Whether this recording has no provider transcript and has to be transcribed from its
     * audio instead (see ProvidesRecordingAudio). Always false for a provider that can't hand
     * over audio — those only ever list recordings that have transcripts.
     */
    public function needsAudioTranscription(Organization $organization, string $recordingId): bool
    {
        $driver = $this->driver($organization);

        return $driver instanceof ProvidesRecordingAudio
            && ! $driver->hasTranscript($organization->meeting_config ?? [], $recordingId);
    }

    /**
     * Streams a recording's media file from the provider to $destinationPath.
     */
    public function downloadRecording(Organization $organization, string $recordingId, string $destinationPath): void
    {
        $driver = $this->driver($organization);

        if (! $driver instanceof ProvidesRecordingAudio) {
            throw new \RuntimeException("Meeting provider [{$organization->meeting_provider}] can't provide recording audio.");
        }

        $driver->downloadRecording($organization->meeting_config ?? [], $recordingId, $destinationPath);
    }
}
