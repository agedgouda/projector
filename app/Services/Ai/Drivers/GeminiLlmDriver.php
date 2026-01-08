<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiLlmDriver implements LlmDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('services.gemini.key');
        // Using the stable 2.5 Flash model
        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $fullPrompt = "{$systemPrompt}\n\n{$userPrompt}";

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [
                    ['parts' => [['text' => $fullPrompt]]]
                ]
            ]);

            // 1. Handle API/HTTP Failures (429 Rate Limit, 403 Key Error, etc.)
            if ($response->failed()) {
                $errorType = $response->status() === 429 ? 'rate_limit' : 'api_error';
                Log::error("Gemini API Failure", ['status' => $response->status(), 'body' => $response->body()]);

                return [
                    'status' => 'error',
                    'error_type' => $errorType,
                    'message' => "Gemini API returned status " . $response->status(),
                    'content' => []
                ];
            }

            $data = $response->json();

            // 2. Handle Safety/Finish Reasons
            // If Google blocks the content, the 'parts' array will be missing.
            $candidate = $data['candidates'][0] ?? null;
            $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';

            if ($finishReason !== 'STOP') {
                return [
                    'status' => 'error',
                    'error_type' => 'safety_block',
                    'message' => "Gemini stopped generating. Reason: {$finishReason}",
                    'content' => []
                ];
            }

            $textResponse = $candidate['content']['parts'][0]['text'] ?? null;

            if (!$textResponse) {
                return [
                    'status' => 'error',
                    'error_type' => 'empty_response',
                    'message' => "Gemini returned no text content.",
                    'content' => []
                ];
            }

            // 3. Clean and Parse JSON
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $textResponse));
            $decoded = json_decode($cleanJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Gemini JSON Parse Error", ['raw' => $cleanJson]);
                return [
                    'status' => 'error',
                    'error_type' => 'json_parse',
                    'message' => "JSON Parse Error: " . json_last_error_msg(),
                    'content' => []
                ];
            }

            return [
                'status' => 'success',
                'content' => $decoded ?? []
            ];

        } catch (Exception $e) {
            Log::error("Gemini Driver Exception: " . $e->getMessage());
            return [
                'status' => 'error',
                'error_type' => 'exception',
                'message' => $e->getMessage(),
                'content' => []
            ];
        }
    }
}
