<?php

/**
 * Cost per 1,000,000 tokens in USD for each AI provider and model.
 * Ollama is excluded (self-hosted, no per-token cost).
 */
return [
    'openai' => [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'gpt-4' => ['input' => 30.00, 'output' => 60.00],
        'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
        // Embeddings (output tokens not applicable)
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0],
    ],
    'gemini' => [
        'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
        'gemini-2.0-flash-lite' => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-flash' => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-pro' => ['input' => 1.25, 'output' => 5.00],
        // Embeddings
        'text-embedding-004' => ['input' => 0.00, 'output' => 0],
        'gemini-embedding-001' => ['input' => 0.00, 'output' => 0],
    ],
    'anthropic' => [
        'claude-opus-4-6' => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku-4-5-20251001' => ['input' => 0.80, 'output' => 4.00],
    ],
];
