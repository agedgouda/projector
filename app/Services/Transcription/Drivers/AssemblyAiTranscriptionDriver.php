<?php

namespace App\Services\Transcription\Drivers;

use App\Contracts\TranscriptionDriver;
use Illuminate\Support\Facades\Http;

class AssemblyAiTranscriptionDriver implements TranscriptionDriver
{
    private const BASE_URL = 'https://api.assemblyai.com/v2';

    public function submit(string $absoluteFilePath): string
    {
        $uploadResponse = Http::withHeaders(['authorization' => config('services.assemblyai.key')])
            ->withBody(file_get_contents($absoluteFilePath) ?: '', 'application/octet-stream')
            ->timeout(120)
            ->post(self::BASE_URL.'/upload');

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

        return [
            'status' => is_string($status) ? $status : 'error',
            'text' => is_string($text) ? $text : null,
            'error' => is_string($error) ? $error : null,
        ];
    }
}
