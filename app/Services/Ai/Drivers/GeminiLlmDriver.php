<?php

namespace App\Services\Ai\Drivers;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiLlmDriver extends AbstractLlmDriver
{
    public function call(string $systemPrompt, string $userPrompt, ?array $responseSchema = null): array
    {
        $useCustomSchema = $responseSchema !== null;
        // The prompt only asks for "assignee_name" when the caller found @-mentioned
        // candidates to match against — the schema needs room for it in that case, or
        // Gemini's structured-output mode will drop the field regardless of prompt text.
        $includeAssignee = str_contains($userPrompt, '"assignee_name"');
        // Same reasoning as $includeAssignee: the prompt only asks for "image_ids" when the
        // caller found uploaded images to match against, and strict structured-output mode
        // drops any field not declared here regardless of what the prompt text says.
        $includeImageIds = str_contains($userPrompt, '"image_ids"');
        // Same reasoning as $includeAssignee: the prompt only asks for "tag_names" when the
        // project has tags to choose from.
        $includeTags = str_contains($userPrompt, '"tag_names"');
        $apiKey = config('services.gemini.key');
        // Using Gemini 2.0 or 1.5 Flash for speed and schema support
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(60)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    ['parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $useCustomSchema ? $responseSchema : $this->getOutputSchema('content', $includeAssignee, $includeImageIds, $includeTags),
                ],
            ]);

            if ($response->failed()) {
                Log::error('Gemini API Failure', ['body' => $response->body()]);

                return ['status' => 'error', 'message' => 'Gemini Error: '.$response->status(), 'content' => []];
            }

            $data = $response->json();
            $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $usage = $data['usageMetadata'] ?? [];

            // No regex cleanup needed; responseSchema guarantees valid JSON
            $decoded = json_decode($textResponse, true);

            return [
                'status' => 'success',
                'content' => $useCustomSchema ? ($decoded ?? []) : ($decoded['items'] ?? []),
                'input_tokens' => $usage['promptTokenCount'] ?? 0,
                'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'driver' => 'gemini',
                'model' => $model,
            ];

        } catch (Exception $e) {
            Log::error('Gemini Driver Exception: '.$e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage(), 'content' => []];
        }
    }

    public function completeFreeform(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(60)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    ['parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                ],
            ]);

            if ($response->failed()) {
                Log::error('Gemini API Failure', ['body' => $response->body()]);

                return ['status' => 'error', 'message' => 'Gemini Error: '.$response->status()];
            }

            $data = $response->json();
            $usage = $data['usageMetadata'] ?? [];

            return [
                'status' => 'success',
                'content' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
                'input_tokens' => $usage['promptTokenCount'] ?? 0,
                'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'driver' => 'gemini',
                'model' => $model,
            ];

        } catch (Exception $e) {
            Log::error('Gemini Driver Exception: '.$e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
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
            Log::error('Gemini Embedding Exception: '.$e->getMessage());

            return [];
        }
    }
}
