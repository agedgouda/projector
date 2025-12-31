<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel; // Ensure this is imported
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentVectorized implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Document $document) {}

    public function broadcastOn(): array
    {
        // Must be PrivateChannel to match your Vue 'private' setting
        return [
            new PrivateChannel('project.' . $this->document->project_id),
        ];
    }

    public function broadcastAs(): string
    {
        // Ensure NO brackets [] around this string
        return 'document.vectorized';
    }
}
