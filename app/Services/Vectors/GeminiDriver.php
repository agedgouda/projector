<?php

namespace App\Services\Vectors;

use App\Contracts\VectorDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiDriver implements VectorDriver
{
    /**
     * Convert text into a 768-dimension vector using Gemini.
     */
    public function getEmbedding(string $text): array
    {
        $apiKey = config('services.gemini.key');
        $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent';

        try {
            $response = Http::post("{$baseUrl}?key={$apiKey}", [
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
            $values = $response->json('embedding.values');

            if (empty($values)) {
                throw new Exception('Gemini returned an empty embedding.');
            }

            return $values;

        } catch (Exception $e) {
            Log::error('Gemini Vector Generation Failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
