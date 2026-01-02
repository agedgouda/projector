<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OllamaLlmDriver implements LlmDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $host = config('services.ollama.host');
        $model = 'deepseek-r1:8b';

        Log::info("Ollama Driver: Starting request to {$model}");

        try {
            $response = Http::timeout(300)->post("{$host}/api/generate", [
                'model'  => $model,
                'system' => $systemPrompt,
                'prompt' => $userPrompt,
                'stream' => false,
                'format' => 'json',
            ]);

            if ($response->failed()) {
                Log::error("Ollama API Connection Failed", ['status' => $response->status(), 'body' => $response->body()]);
                throw new Exception("Ollama API Error: " . $response->status());
            }

            $rawText = $response->json('response');
            Log::info("Ollama Step 1: Raw Response Received", ['length' => strlen($rawText)]);

            // 1. Remove DeepSeek reasoning <think> blocks
            $noThinking = preg_replace('/<think>.*?<\/think>/s', '', $rawText);

            // 2. Remove Markdown code blocks (matching your Gemini driver logic)
            $cleanJson = trim(preg_replace('/^```json\s*|```$/m', '', $noThinking));

            Log::info("Ollama Step 2: Text cleaned of Thinking and Markdown");

            // 3. Attempt JSON Decode
            $decoded = json_decode($cleanJson, true);

            // 4. Safety Net: If AI returned a single object {}, wrap it in an array [{}]
            if ($decoded && !isset($decoded[0]) && isset($decoded['title'])) {
                Log::info("Ollama Step 3: Single object detected. Wrapping in array.");
                $decoded = [$decoded];
            }

            if (!$decoded) {
                Log::error("Ollama Step 4: JSON Decode Failed", ['cleaned_text' => $cleanJson]);
            } else {
                Log::info("Ollama Step 4: Successfully decoded " . count($decoded) . " items.");
            }

            return [
                'status'  => $decoded ? 'success' : 'error',
                'content' => $decoded ?? []
            ];

        } catch (Exception $e) {
            Log::error("Ollama Driver Exception: " . $e->getMessage());
            return [
                'status'  => 'error',
                'content' => []
            ];
        }
    }
}
