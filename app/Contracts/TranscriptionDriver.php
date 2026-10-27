<?php

namespace App\Contracts;

interface TranscriptionDriver
{
    /**
     * Upload a local audio file and start transcription. Returns the vendor's transcript ID,
     * used to poll for completion since transcription of a full-length recording is async.
     */
    public function submit(string $absoluteFilePath): string;

    /**
     * usage is what the vendor bills for, once known (a completed transcript) — audio length
     * and the model that actually ran.
     *
     * @return array{status: string, text: ?string, error: ?string, usage?: ?array{driver: string, model: string, audio_seconds: int}} status is one of
     *                                                                                                                                 'queued', 'processing', 'completed', 'error'.
     */
    public function poll(string $transcriptId): array;
}
