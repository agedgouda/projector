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

    public function __construct(
        public Document $document,
        public string $statusMessage,
        public int $progress = 0 // Added progress property
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.' . $this->document->project_id),
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
            'document_id'   => $this->document->id,
            'progress'      => $this->progress, // Added to broadcast payload
            'document'      => $this->document->load('assignee'), // Keeps assignee synced
        ];
    }
}
