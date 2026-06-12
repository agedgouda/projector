<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentProcessingUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $deletedDocumentIds  IDs of documents removed from the
     *                                                  traceability tree (e.g. stale children
     *                                                  replaced by reprocessing).
     * @param  array<int, string>  $newDocumentIds  IDs of newly created child documents.
     */
    public function __construct(
        public Document $document,
        public string $statusMessage,
        public int $progress = 0, // Added progress property
        public array $deletedDocumentIds = [],
        public array $newDocumentIds = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.'.$this->document->project_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DocumentProcessingUpdate';
    }

    public function broadcastWith(): array
    {
        return [
            'statusMessage' => $this->statusMessage,
            'document_id' => $this->document->id,
            'progress' => $this->progress,
            'document' => $this->formatDocument($this->document),
            'deletedDocumentIds' => $this->deletedDocumentIds,
            'newDocumentIds' => $this->newDocumentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDocument(Document $document): array
    {
        $document->loadMissing(['assignee', 'project.type']);
        $schema = collect($document->project?->type?->document_schema ?? [])->keyBy('key');
        $typeLabel = $schema->get($document->type)['label'] ?? $document->type;

        return array_merge(
            $document->makeHidden('content')->toArray(),
            ['type_label' => $typeLabel]
        );
    }
}
