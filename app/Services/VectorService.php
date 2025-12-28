<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class VectorService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
    }

    /**
     * Convert text into a 768-dimension vector using Gemini.
     */
    public function getEmbedding(string $text): array
    {
        try {
            $response = Http::post("{$this->baseUrl}?key={$this->apiKey}", [
                'content' => [
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ]);

            if ($response->failed()) {
                throw new Exception('Gemini API Error: ' . $response->body());
            }

            // Gemini returns the vector under 'values'
            return $response->json('embedding.values');

        } catch (Exception $e) {
            Log::error('Vector Generation Failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
