<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Contracts\VectorDriver;
use App\Events\DocumentProcessingUpdate;
use Illuminate\Support\Facades\Log;

class VectorService
{
    /**
     * We now inject the VectorDriver contract.
     * Laravel will provide GeminiVectorDriver or OllamaVectorDriver
     * based on your AppServiceProvider binding.
     */
    public function __construct(
        protected VectorDriver $vectorDriver
    ) {}

    /**
     * Primary entry point for Jobs.
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
            // The VectorDriver now encapsulates the URL (v1 vs v1beta)
            // and the specific model logic.
            $embedding = $this->vectorDriver->getEmbedding($text);

            if (empty($embedding)) {
                throw new \RuntimeException("Driver returned an empty embedding array.");
            }

            return $embedding;

        } catch (\Throwable $e) {
            Log::error("Vector Service Error: " . $e->getMessage(), [
                'document_id' => $document?->id
            ]);

            if ($document) {
                $document->update(['processed_at' => now()]);
                event(new DocumentProcessingUpdate($document, "AI Service Error: Connection Failed", 0));
            }

            throw new \RuntimeException("Vectorization failed: " . $e->getMessage());
        }
    }

    public function searchContext(Project $project, string $queryText, User $user, float $threshold = 0.45)
    {
        $vector = $this->getEmbedding($queryText);

        if (empty($vector)) {
            return collect();
        }

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
