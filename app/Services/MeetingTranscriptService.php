<?php

namespace App\Services;

use App\Contracts\MeetingDriver;
use App\Models\Organization;
use App\Services\Meeting\Drivers\GoogleMeetMeetingDriver;
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
            default => throw new \InvalidArgumentException(
                "Meeting provider [{$provider}] is not supported."
            ),
        };
    }

    /**
     * List recordings available for import.
     *
     * @return array<int, array{id: string, title: string, started_at: string, duration_minutes: int}>
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
}
