<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaLlmDriver implements LlmDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        $host = config('services.ollama.host');

        try {
            $response = Http::timeout(300)->post("{$host}/api/generate", [
                'model' => 'deepseek-r1:8b',
                'system' => $systemPrompt,
                'prompt' => $userPrompt,
                'stream' => false,
                'format' => 'json', // Ollama can force JSON mode!
            ]);

            if ($response->failed()) {
                throw new \Exception("Ollama Error: " . $response->status());
            }

            $text = $response->json('response');

            // DeepSeek-R1 includes <think> blocks. We strip them to get just the JSON.
            $cleanJson = trim(preg_replace('/<think>.*?<\/think>/s', '', $text));

            return [
                'status' => 'success',
                'content' => json_decode($cleanJson, true) ?? []
            ];

        } catch (\Exception $e) {
            Log::error("Ollama Driver Error: " . $e->getMessage());
            return ['status' => 'error', 'content' => []];
        }
    }
}
