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

    public function getEmbedding(string $text): array
    {
        return $this->driver->getEmbedding($text);
    }
}
