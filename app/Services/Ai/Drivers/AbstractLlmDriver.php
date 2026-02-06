<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;

abstract class AbstractLlmDriver implements LlmDriver
{
    protected function getOutputSchema(string $outputType = 'content'): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            // Use the dynamic key here!
                            $outputType => ['type' => 'string'],
                            'criteria' => ['type' => 'array', 'items' => ['type' => 'string']]
                        ],
                        'required' => ['title', $outputType, 'criteria'],
                        'additionalProperties' => false
                    ]
                ]
            ],
            'required' => ['items'],
            'additionalProperties' => false
        ];
    }
}
