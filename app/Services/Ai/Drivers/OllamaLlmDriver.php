<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Http\Client\ConnectionException;

class OllamaLlmDriver implements LlmDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $host = config('services.ollama.host');
        $model = 'deepseek-r1:8b';

        Log::info("Ollama Driver: Starting request", ['model' => $model]);

        try {
            $response = Http::timeout(300)->post("{$host}/api/generate", [
                'model'  => $model,
                'system' => $systemPrompt,
                'prompt' => $userPrompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0.2,
                    'top_p'       => 0.1,
                    'num_predict' => 2048, // Prevents premature cutoff
                ],
            ]);

            // 1. Handle HTTP Failures (e.g., 404 model not found, 500 server crash)
            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'error_type' => 'http_failure',
                    'message' => "Ollama API returned status " . $response->status(),
                    'content' => []
                ];
            }

            $rawText = $response->json('response');

            if (empty($rawText)) {
                return [
                    'status' => 'error',
                    'error_type' => 'empty_response',
                    'message' => "Ollama returned an empty response string.",
                    'content' => []
                ];
            }

            // 2. DeepSeek Clean-up
            $noThinking = preg_replace('/<think>.*?<\/think>/s', '', $rawText);
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $noThinking));

            // 3. JSON Decode with Error Capture
            $decoded = json_decode($cleanJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Ollama JSON Parse Error", [
                    'error' => json_last_error_msg(),
                    'raw_after_cleanup' => $cleanJson
                ]);

                return [
                    'status' => 'error',
                    'error_type' => 'json_parse',
                    'message' => "Failed to parse AI response as JSON: " . json_last_error_msg(),
                    'content' => []
                ];
            }

            // 4. Safety Net: Consistency check
            if (is_array($decoded) && !array_is_list($decoded)) {
                $decoded = [$decoded];
            }

            return [
                'status'  => 'success',
                'content' => is_array($decoded) ? $decoded : []
            ];
        } catch (ConnectionException $e) {
            return [
                'status' => 'error',
                'error_type' => 'connection',
                'message' => "Could not reach Ollama at {$host}. Is the service running?",
                'content' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error_type' => 'exception',
                'message' => $e->getMessage(),
                'content' => []
            ];
        }
    }
}
