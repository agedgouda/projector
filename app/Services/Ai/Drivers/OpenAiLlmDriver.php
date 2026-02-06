<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\{LlmDriver, VectorDriver};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiLlmDriver implements LlmDriver, VectorDriver
{
    public function call(string $systemPrompt, string $userPrompt): array
    {
        // Extract the dynamic key name from the prompt (e.g., 'action_item')
        preg_match('/"([^"]+)", and "criteria"/', $userPrompt, $matches);
        $dynamicKey = $matches[1] ?? 'content';

        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
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
                                                'description' => 'A short, descriptive name for this item.'
                                            ],
                                            $dynamicKey => [
                                                'type' => 'string',
                                                'description' => 'The main content or body of the item.'
                                            ],
                                            'criteria' => [
                                                'type' => 'array',
                                                'items' => ['type' => 'string']
                                            ],
                                        ],
                                        'required' => ['title', $dynamicKey, 'criteria'],
                                        'additionalProperties' => false,
                                    ]
                                ]
                            ],
                            'required' => ['items'],
                            'additionalProperties' => false,
                        ]
                    ]
                ],
                'temperature' => 0.7, // Increased slightly to allow for more creative titles
            ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => $response->body()];
        }

        $decoded = json_decode($response->json('choices.0.message.content'), true);

        // With 'strict' mode, the array is guaranteed to be under the 'items' key
        return [
            'status'  => 'success',
            'content' => $decoded['items'] ?? []
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
            Log::error("OpenAI Embedding Failed", ['body' => $response->body()]);
            return [];
        }

        return $response->json('data.0.embedding');
    }
}
