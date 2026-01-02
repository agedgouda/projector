<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentVectorized implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Document $document)
    {
        // Don't just trust the object passed in; refresh it from the DB
        $this->document = $document->fresh();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.' . $this->document->project_id),
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
        // Since attempts are 0, a simple refresh is sufficient.
        $this->document->refresh();

        return [
            'document' => [
                'id'           => $this->document->id,
                'name'         => $this->document->name,
                'type'         => $this->document->type,
                'parent_id'    => $this->document->parent_id,
                'content'      => $this->document->content,
                'metadata'     => $this->document->metadata, // Criteria lives here
                'processed_at' => $this->document->processed_at?->toIso8601String(),
                'created_at'   => $this->document->created_at?->toIso8601String(),
                'updated_at'   => $this->document->updated_at?->toIso8601String(),
            ],
        ];
    }
}
