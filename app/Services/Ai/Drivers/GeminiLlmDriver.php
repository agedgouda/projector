<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiLlmDriver implements LlmDriver
{
    /**
     * Core communication with Gemini 2.5 Flash.
     */
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('services.gemini.key');
        // Using the 2.5 Flash model as per your original code
        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        // Combine the prompts as Gemini expects them in the contents array
        $fullPrompt = "{$systemPrompt}\n\n{$userPrompt}";

        try {
            $response = Http::post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $fullPrompt]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                Log::error("Gemini API Error Body: " . $response->body());
                throw new Exception("Gemini API Error: " . $response->status());
            }

            $data = $response->json();
            $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';

            // Clean up any markdown formatting (your original regex)
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $textResponse));

            return [
                'status' => 'success',
                'content' => json_decode($cleanJson, true) ?? []
            ];

        } catch (Exception $e) {
            Log::error("Gemini Driver Error: " . $e->getMessage());
            return [
                'status' => 'error',
                'content' => []
            ];
        }
    }
}
