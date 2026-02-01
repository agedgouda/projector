<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Contracts\VectorDriver;
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use App\Events\DocumentProcessingUpdate;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;

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

    /**
     * Primary entry point for Jobs.
     * Handles content extraction and automatic error cleanup.
     */
    public function getEmbeddingForDocument(Document $document): array
    {
        return $this->getEmbedding($document->content, $document);
    }

    /**
     * General purpose embedding generator.
     */
    public function getEmbedding(string $text, ?Document $document = null): array
    {
        try {
            return $this->driver->getEmbedding($text);
        } catch (\Throwable $e) {
            // Log the failure centrally
            Log::error("Vector Service Error: " . $e->getMessage(), [
                'driver' => config('services.vector_driver'),
                'document_id' => $document?->id
            ]);

            if ($document) {
                // IMPORTANT: Setting this kills the "stuck" spinner in Vue
                $document->update(['processed_at' => now()]);

                // Notifies the frontend of the error state
                event(new DocumentProcessingUpdate($document, "AI Service Error: Connection Failed", 0));
            }

            // Rethrow so the Job enters its own failed() lifecycle
            throw new \RuntimeException("Vectorization failed: " . $e->getMessage());
        }
    }

    public function searchContext(Project $project, string $queryText, User $user, float $threshold = 0.45)
    {
        $vector = $this->getEmbedding($queryText);
        $vectorString = '[' . implode(',', $vector) . ']';

        return Document::query()
            ->visibleTo($user)
            ->where('project_id', $project->id)
            ->select('id', 'content', 'type')
            ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vectorString])
            ->whereRaw('1 - (embedding <=> ?::vector) > ?', [$vectorString, $threshold])
            ->orderBy('similarity', 'DESC')
            ->limit(5)
            ->get();
    }
}
