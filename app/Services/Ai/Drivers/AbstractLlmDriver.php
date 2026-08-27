<?php

namespace App\Services\Ai\Drivers;

use App\Contracts\LlmDriver;

abstract class AbstractLlmDriver implements LlmDriver
{
    protected function getEmbeddingDimensions(): int
    {
        return 1536;
    }

    protected function getOutputSchema(string $outputType = 'content', bool $includeAssignee = false, bool $includeImageIds = false, bool $includeTags = false): array
    {
        $properties = [
            'title' => ['type' => 'string'],
            // Use the dynamic key here!
            $outputType => ['type' => 'string'],
            'criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
        ];
        $required = ['title', $outputType, 'criteria', 'priority'];

        if ($includeAssignee) {
            $properties['assignee_name'] = ['type' => 'string', 'nullable' => true];
            $required[] = 'assignee_name';
        }

        if ($includeImageIds) {
            $properties['image_ids'] = ['type' => 'array', 'items' => ['type' => 'integer']];
            $required[] = 'image_ids';
        }

        if ($includeTags) {
            $properties['tag_names'] = ['type' => 'array', 'items' => ['type' => 'string']];
            $required[] = 'tag_names';
        }

        return [
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
        ];
    }
}
