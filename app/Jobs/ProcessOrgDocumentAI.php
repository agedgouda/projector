<?php

namespace App\Jobs;

use App\Models\OrgDocument;
use App\Models\Project;
use App\Services\Ai\OrgAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOrgDocumentAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 5;

    public function __construct(public readonly OrgDocument $orgDocument) {}

    public function handle(OrgAiService $aiService): void
    {
        $organization = $this->orgDocument->organization;
        $organization->applyDriverConfig();

        $activeProjects = Project::whereHas('client', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('inactive', false)
            ->with('client:id,name')
            ->get(['id', 'name', 'client_id']);

        $draft = $aiService->extractActionItems($this->orgDocument, $activeProjects);

        $this->orgDocument->updateQuietly([
            'metadata' => array_merge($this->orgDocument->metadata ?? [], [
                'ai_draft' => $draft,
            ]),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessOrgDocumentAI failed', [
            'org_document_id' => $this->orgDocument->id,
            'error' => $exception->getMessage(),
        ]);

        $this->orgDocument->updateQuietly([
            'metadata' => array_merge($this->orgDocument->metadata ?? [], [
                'ai_draft' => ['status' => 'failed', 'error' => $exception->getMessage()],
            ]),
        ]);
    }
}
