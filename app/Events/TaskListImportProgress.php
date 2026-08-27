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
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.'.$this->importDocument->project_id),
        ];
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
