<?php

namespace App\Services\Vectors;

use App\Contracts\VectorDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OllamaDriver implements VectorDriver
{
    public function getEmbedding(string $text): array
    {
        $host = config('services.ollama.host');

        try {
            $response = Http::post("{$host}/api/embeddings", [
                'model' => 'nomic-embed-text',
                'prompt' => $text,
            ]);

            if ($response->failed()) {
                throw new Exception('Ollama API Error: ' . $response->body());
            }

            return $response->json('embedding');
        } catch (Exception $e) {
            Log::error('Ollama Vector Generation Failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
