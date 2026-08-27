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
     * @param  bool  $childrenReplaced  Whether this document already had children that were
     *                                  replaced (e.g. stale output cleared by reprocessing).
     *                                  Listeners prune their own local tree from this
     *                                  document's id rather than being handed the removed
     *                                  IDs directly, which for a large batch can alone exceed
     *                                  the broadcaster's payload limit.
     * @param  int  $newDocumentCount  Number of newly created child documents. As with
     *                                 $childrenReplaced, listeners derive which documents
     *                                 those are from parent_id on each child's own
     *                                 broadcasts rather than an explicit ID list.
     */
    public function __construct(
        public Document $document,
        public string $statusMessage,
        public int $progress = 0, // Added progress property
        public bool $childrenReplaced = false,
        public int $newDocumentCount = 0,
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
            'childrenReplaced' => $this->childrenReplaced,
            'newDocumentCount' => $this->newDocumentCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDocument(Document $document): array
    {
        $document->loadMissing(['assignee', 'project']);
        $typeLabel = $document->project?->documentTypeCatalog()->get($document->type)?->label ?? $document->type;

        return array_merge(
            $document->makeHidden('content')->toArray(),
            ['type_label' => $typeLabel]
        );
    }
}
