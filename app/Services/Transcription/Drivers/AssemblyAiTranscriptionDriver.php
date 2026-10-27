<?php

namespace App\Services\Transcription\Drivers;

use App\Contracts\TranscriptionDriver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

class AssemblyAiTranscriptionDriver implements TranscriptionDriver
{
    private const BASE_URL = 'https://api.assemblyai.com/v2';

    public function submit(string $absoluteFilePath): string
    {
        $stream = fopen($absoluteFilePath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Could not read recording file [{$absoluteFilePath}].");
        }

        // Streamed rather than read into memory — a meeting recording can be up to 2 GB. The
        // long timeout is for that same case; the job runs on the long-running recordings queue.
        try {
            $uploadResponse = Http::withHeaders(['authorization' => config('services.assemblyai.key')])
                ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                ->timeout(3600)
                ->post(self::BASE_URL.'/upload');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($uploadResponse->failed()) {
            throw new \RuntimeException('AssemblyAI upload failed: '.$uploadResponse->body());
        }

        $uploadUrl = $uploadResponse->json('upload_url');

        if (! is_string($uploadUrl)) {
            throw new \RuntimeException('AssemblyAI upload did not return an upload_url.');
        }

        $transcriptResponse = Http::withHeaders(['authorization' => config('services.assemblyai.key')])
            ->timeout(30)
            ->post(self::BASE_URL.'/transcript', [
                'audio_url' => $uploadUrl,
                'speaker_labels' => true,
            ]);

        if ($transcriptResponse->failed()) {
            throw new \RuntimeException('AssemblyAI transcript request failed: '.$transcriptResponse->body());
        }

        $transcriptId = $transcriptResponse->json('id');

        if (! is_string($transcriptId)) {
            throw new \RuntimeException('AssemblyAI transcript request did not return an id.');
        }

        return $transcriptId;
    }

    public function poll(string $transcriptId): array
    {
        $response = Http::withHeaders(['authorization' => config('services.assemblyai.key')])
            ->timeout(30)
            ->get(self::BASE_URL."/transcript/{$transcriptId}");

        if ($response->failed()) {
            return ['status' => 'error', 'text' => null, 'error' => $response->body()];
        }

        $status = $response->json('status');
        $text = $response->json('text');
        $error = $response->json('error');
        $audioSeconds = $response->json('audio_duration');
        $model = $response->json('speech_model_used');

        return [
            'status' => is_string($status) ? $status : 'error',
            'text' => is_string($text) ? $text : null,
            'error' => is_string($error) ? $error : null,
            'usage' => is_numeric($audioSeconds) ? [
                'driver' => 'assemblyai',
                'model' => is_string($model) ? $model : 'unknown',
                'audio_seconds' => (int) ceil((float) $audioSeconds),
            ] : null,
        ];
    }
}
