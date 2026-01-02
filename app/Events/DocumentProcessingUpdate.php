<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets; // MUST HAVE
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable; // MUST HAVE
use Illuminate\Queue\SerializesModels;

class DocumentProcessingUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Document $document,
        public string $statusMessage
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
            'document_id' => $this->document->id,
        ];
    }
}
