<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class DocumentProcessingUpdate implements ShouldBroadcast
{
    public function __construct(
        public Document $document,
        public string $statusMessage
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel("project.{$this->document->project_id}");
    }
}
