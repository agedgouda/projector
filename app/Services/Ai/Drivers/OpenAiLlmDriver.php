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

            // The prompt only asks for "assignee_name" when the caller found @-mentioned
            // candidates to match against — mirror that here so the strict schema below
            // actually has room for it. Without this, OpenAI's structured-output mode
            // rejects (or silently drops) any key not declared here, regardless of what
            // the prompt text says.
            $includeAssignee = str_contains($userPrompt, '"assignee_name"');
            // Same reasoning as $includeAssignee: the prompt only asks for "image_ids" when
            // the caller found uploaded images to match against, and strict structured-output
            // mode drops any field not declared here regardless of what the prompt text says.
            $includeImageIds = str_contains($userPrompt, '"image_ids"');

            $properties = [
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
                'start_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO 8601 date (YYYY-MM-DD) the item begins, for something that spans a range of dates (e.g. a calendar event), or null.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                    'description' => "The item's priority level.",
                ],
            ];
            $required = ['title', $dynamicKey, 'criteria', 'due_date', 'start_date', 'priority'];

            if ($includeAssignee) {
                $properties['assignee_name'] = [
                    'type' => ['string', 'null'],
                    'description' => 'The exact name of the person this item is assigned to, from the candidate names given in the prompt, or null if none clearly applies.',
                ];
                $required[] = 'assignee_name';
            }

            if ($includeImageIds) {
                $properties['image_ids'] = [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Numbers of any uploaded images from the source document, given in the prompt, that clearly belong with this item — or an empty array if none do.',
                ];
                $required[] = 'image_ids';
            }

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
                                'properties' => $properties,
                                'required' => $required,
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
            ->timeout(240)
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

    public function completeFreeform(string $systemPrompt, string $userPrompt): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(240)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
            ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => $response->body()];
        }

        $usage = $response->json('usage') ?? [];

        return [
            'status' => 'success',
            'content' => (string) $response->json('choices.0.message.content'),
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
