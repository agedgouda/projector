<?php

namespace App\Contracts;

/**
 * A MeetingDriver whose provider can hand over a meeting's recorded audio/video when it has no
 * transcript of its own (e.g. a Google Meet meeting that was recorded without transcripts
 * turned on). Such a recording is listed like any other and, on import, transcribed by
 * Projector itself instead of fetched as text.
 */
interface ProvidesRecordingAudio
{
    /**
     * Whether the provider has its own transcript for this recording — false means it has to
     * be transcribed from its audio instead.
     *
     * @param  array<string, mixed>  $config  decrypted meeting_config from the Organization
     */
    public function hasTranscript(array $config, string $recordingId): bool;

    /**
     * Streams the recording's media file to $destinationPath (an absolute local path).
     *
     * @param  array<string, mixed>  $config  decrypted meeting_config from the Organization
     */
    public function downloadRecording(array $config, string $recordingId, string $destinationPath): void;
}
