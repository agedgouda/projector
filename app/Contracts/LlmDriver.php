<?php

namespace App\Contracts;

interface LlmDriver
{
    /**
     * @return array ['status' => 'success', 'content' => array]
     */
    public function call(string $systemPrompt, string $userPrompt): array;

}
