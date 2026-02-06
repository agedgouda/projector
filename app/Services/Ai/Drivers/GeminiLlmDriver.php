<?php

namespace App\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiLlmDriver extends AbstractLlmDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('services.gemini.key');
        // Using Gemini 2.0 or 1.5 Flash for speed and schema support
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(60)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]]
                ],
                'contents' => [
                    ['parts' => [['text' => $userPrompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    // This is the magic: Gemini's version of Structured Outputs
                    'responseSchema' => $this->getOutputSchema(),
                ]
            ]);

            if ($response->failed()) {
                Log::error("Gemini API Failure", ['body' => $response->body()]);
                return ['status' => 'error', 'message' => "Gemini Error: " . $response->status(), 'content' => []];
            }

            $data = $response->json();
            $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

            // No regex cleanup needed; responseSchema guarantees valid JSON
            $decoded = json_decode($textResponse, true);

            return [
                'status' => 'success',
                // Always return the flat 'items' array to match OpenAI/Ollama drivers
                'content' => $decoded['items'] ?? []
            ];

        } catch (Exception $e) {
            Log::error("Gemini Driver Exception: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage(), 'content' => []];
        }
    }

    public function getEmbedding(string $text): array
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.embed_model', 'text-embedding-004');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'content' => ['parts' => [['text' => $text]]],
                // Lock dimensionality to 1536 to match your DB and OpenAI/Ollama
                'outputDimensionality' => $this->getEmbeddingDimensions(),
                'taskType' => 'RETRIEVAL_DOCUMENT',
            ]);

            return $response->json('embedding.values') ?? [];

        } catch (Exception $e) {
            Log::error("Gemini Embedding Exception: " . $e->getMessage());
            return [];
        }
    }
}
