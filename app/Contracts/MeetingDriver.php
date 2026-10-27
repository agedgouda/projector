<?php

namespace App\Contracts;

interface MeetingDriver
{
    /**
     * List available recordings that have transcripts — or, for a driver that also implements
     * ProvidesRecordingAudio, recordings Projector can transcribe itself.
     *
     * Returns an array of recordings, each with:
     *   - id: string        unique recording ID from the provider
     *   - title: string     meeting/recording title
     *   - started_at: string ISO 8601 datetime
     *   - duration_minutes: int
     *   - has_transcript: bool (optional, true when absent) — false for a recording that will
     *     be transcribed from its audio on import
     *
     * @param  array  $config  decrypted meeting_config from the Organization
     * @return array<int, array{id: string, title: string, started_at: string, duration_minutes: int, has_transcript?: bool}>
     */
    public function listRecordings(array $config, \DateTimeInterface $since): array;

    /**
     * Fetch the plain-text transcript for a single recording.
     *
     * @param  array  $config  decrypted meeting_config from the Organization
     * @param  string  $recordingId  provider-specific recording ID
     */
    public function fetchTranscript(array $config, string $recordingId): string;
}
