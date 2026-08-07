<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentVectorized implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Document $document)
    {
        // Don't just trust the object passed in; refresh it from the DB — but if the
        // document has been deleted in the meantime (e.g. a concurrent reprocess
        // replacing its parent's other children), fresh() returns null; fall back to
        // what we were given rather than crash on assigning null to this property.
        $this->document = $document->fresh() ?? $document;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.'.$this->document->project_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'document.vectorized';
    }

    /**
     * THIS IS THE VITAL PART:
     * We manually build the array to ensure processed_at is NOT null.
     */
    public function broadcastWith(): array
    {
        // Since attempts are 0, a simple refresh is sufficient — same null-safety as the
        // constructor. Model::refresh() uses firstOrFail() internally and would throw if
        // the document has since been deleted; fresh() degrades to null instead, so this
        // broadcasts the last known state rather than crashing.
        $this->document = $this->document->fresh() ?? $this->document;

        return [
            'document' => [
                'id' => $this->document->id,
                'name' => $this->document->name,
                'type' => $this->document->type,
                'parent_id' => $this->document->parent_id,
                'metadata' => $this->document->metadata,
                'processed_at' => $this->document->processed_at?->toIso8601String(),
                'created_at' => $this->document->created_at?->toIso8601String(),
                'updated_at' => $this->document->updated_at?->toIso8601String(),
            ],
        ];
    }
}
