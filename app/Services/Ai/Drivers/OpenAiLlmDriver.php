<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;
use App\Contracts\VectorDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiLlmDriver implements LlmDriver, VectorDriver
{
    public function call(string $systemPrompt, string $userPrompt, ?array $responseSchema = null): array
    {
        $useCustomSchema = $responseSchema !== null;

        if ($useCustomSchema) {
            $jsonSchema = [
                'name' => 'custom_extraction',
                'strict' => false,
                'schema' => $responseSchema,
            ];
        } else {
            preg_match('/"([^"]+)", and "criteria"/', $userPrompt, $matches);
            $dynamicKey = $matches[1] ?? 'content';

            $jsonSchema = [
                'name' => 'document_extraction',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'title' => [
                                        'type' => 'string',
                                        'description' => 'A short, descriptive name for this item.',
                                    ],
                                    $dynamicKey => [
                                        'type' => 'string',
                                        'description' => 'The main content or body of the item.',
                                    ],
                                    'criteria' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                    'due_date' => [
                                        'type' => ['string', 'null'],
                                        'description' => 'ISO 8601 date (YYYY-MM-DD) extracted from deadline or delivery language, or null.',
                                    ],
                                ],
                                'required' => ['title', $dynamicKey, 'criteria', 'due_date'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['items'],
                    'additionalProperties' => false,
                ],
            ];
        }

        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => $jsonSchema,
                ],
                'temperature' => 0.7,
            ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => $response->body()];
        }

        $decoded = json_decode($response->json('choices.0.message.content'), true);
        $usage = $response->json('usage') ?? [];

        return [
            'status' => 'success',
            'content' => $useCustomSchema ? ($decoded ?? []) : ($decoded['items'] ?? []),
            'input_tokens' => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
            'driver' => 'openai',
            'model' => config('services.openai.model', 'gpt-4o-mini'),
        ];
    }

    public function getEmbedding(string $text): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

        if ($response->failed()) {
            Log::error('OpenAI Embedding Failed', ['body' => $response->body()]);

            return [];
        }

        return $response->json('data.0.embedding');
    }
}
