<?php

namespace App\Services;

use App\Contracts\VectorDriver;
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use InvalidArgumentException;

class VectorService
{
    protected VectorDriver $driver;

    public function __construct()
    {
        // In Octane, this runs once per request if bound as 'scoped'
        $name = config('services.vector_driver', 'gemini');

        $this->driver = match ($name) {
            'gemini' => new GeminiDriver(),
            'ollama' => new OllamaDriver(),
            default  => throw new InvalidArgumentException("Driver [{$name}] not supported."),
        };
    }

    public function searchContext(Project $project, string $queryText, User $user, float $threshold = 0.45)
    {
        $vector = $this->getEmbedding($queryText);
        $vectorString = '[' . implode(',', $vector) . ']';

        return Document::query()
            ->visibleTo($user) // The Security Gate
            ->where('project_id', $project->id)
            ->select('id', 'content', 'type')
            ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vectorString])
            ->whereRaw('1 - (embedding <=> ?::vector) > ?', [$vectorString, $threshold])
            ->orderBy('similarity', 'DESC')
            ->limit(5)
            ->get();
    }

    public function getEmbedding(string $text): array
    {
        return $this->driver->getEmbedding($text);
    }
}
