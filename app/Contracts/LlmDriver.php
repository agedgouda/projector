<?php

namespace App\Contracts;

interface LlmDriver
{
    /**
     * @param  array|null  $responseSchema  Custom JSON schema for the response. When provided, `content` is the full decoded object.
     * @return array ['status' => 'success', 'content' => array]
     */
    public function call(string $systemPrompt, string $userPrompt, ?array $responseSchema = null): array;
}
