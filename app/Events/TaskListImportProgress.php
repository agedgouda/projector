<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class TaskListImportProgress implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @param  'running'|'done'|'error'  $status
     */
    public function __construct(
        public Document $importDocument,
        public int $processed,
        public int $total,
        public string $status,
        public ?string $redirectUrl = null,
        public ?string $message = null,
        public ?string $warning = null,
        public ?int $triggeredByUserId = null,
    ) {}

    /**
     * Private to the user who triggered this import — unlike DocumentProcessingUpdate/
     * DocumentVectorized, nothing else needs the wider project/org audience for this event (no
     * shared data-sync concern; useTaskListImportProgress.ts and useGlobalImportActivity.ts are
     * its only consumers, and redirect_url only ever makes sense for the initiating browser).
     */
    public function broadcastOn(): array
    {
        if (! $this->triggeredByUserId) {
            return [];
        }

        return [new PrivateChannel('user.'.$this->triggeredByUserId)];
    }

    public function broadcastAs(): string
    {
        return 'TaskListImportProgress';
    }

    public function broadcastWith(): array
    {
        return [
            'import_document_id' => $this->importDocument->id,
            'processed' => $this->processed,
            'total' => $this->total,
            'status' => $this->status,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'warning' => $this->warning,
        ];
    }
}
