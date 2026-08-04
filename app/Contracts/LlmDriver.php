<?php

namespace App\Contracts;

interface LlmDriver
{
    /**
     * @param  array|null  $responseSchema  Custom JSON schema for the response. When provided, `content` is the full decoded object.
     * @return array ['status' => 'success', 'content' => array]
     */
    public function call(string $systemPrompt, string $userPrompt, ?array $responseSchema = null): array;

    /**
     * A plain, unconstrained text completion — no JSON schema or structured-output mode — for
     * callers that want the model's own natural formatting rather than extraction into a fixed
     * shape (used by a document's own custom_prompt override, never by template-driven
     * extraction, which always goes through call() instead).
     *
     * @return array ['status' => 'success', 'content' => string] or ['status' => 'error', 'message' => string]
     */
    public function completeFreeform(string $systemPrompt, string $userPrompt): array;
}
