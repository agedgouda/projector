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
        // Using config instead of hardcoded strings
        $host = config('services.ollama.host', 'http://localhost:11434');
        $model = config('services.ollama.model', 'deepseek-r1:8b');

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
                    'num_predict' => 2048,
                ],
            ]);

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

            $noThinking = preg_replace('/<think>.*?<\/think>/s', '', $rawText);
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $noThinking));

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

    /**
     * Generate vectors locally using Ollama.
     */
    public function getEmbedding(string $text): array
    {
        $host = config('services.ollama.host', 'http://localhost:11434');

        // Pointing to the new config key for embeddings
        $model = config('services.ollama.embedding_model', 'nomic-embed-text');

        try {
            $response = Http::timeout(30)->post("{$host}/api/embed", [
                'model' => $model,
                'input' => $text
            ]);

            if ($response->failed()) {
                Log::error("Ollama Embedding Failure", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();

            return $data['embeddings'][0] ?? [];

        } catch (Exception $e) {
            Log::error("Ollama Embedding Exception: " . $e->getMessage());
            return [];
        }
    }
}
