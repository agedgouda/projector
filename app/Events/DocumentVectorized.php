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
        // Log the actual DB state vs the Model state at this moment
        $dbValue = \DB::table('documents')->where('id', $this->document->id)->value('processed_at');

        return [
            'document' => [
                'id' => $this->document->id,
                'name' => $this->document->name,
                'type' => $this->document->type,
                'parent_id' => $this->document->parent_id,
                'processed_at' => $this->document->processed_at ? $this->document->processed_at->toIso8601String() : null,
            ],
        ];
    }
}
