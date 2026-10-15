<?php

namespace App\Console\Commands;

use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Models\OrgDocument;
use Illuminate\Console\Command;

class ReembedOrgDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reembed-org-documents {--dry-run : Report how many would be queued without queueing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue embedding generation for every org document that has content but no embedding. Safe to re-run.';

    public function handle(): int
    {
        $query = OrgDocument::query()
            ->whereNull('embedding')
            ->whereNotNull('content')
            ->where('content', '!=', '');

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} org document(s) would be queued for embedding.");

            return self::SUCCESS;
        }

        $query->select(['id', 'organization_id'])->lazyById()->each(function (OrgDocument $orgDocument): void {
            GenerateOrgDocumentEmbedding::dispatch($orgDocument);
        });

        $this->info("Queued {$count} org document(s) for embedding.");

        return self::SUCCESS;
    }
}
