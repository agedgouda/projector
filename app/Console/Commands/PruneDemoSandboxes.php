<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSandbox;
use Illuminate\Console\Command;

class PruneDemoSandboxes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:prune';

    /**
     * @var string
     */
    protected $description = 'Delete demo sandboxes (their organization, projects, documents and accounts) older than '.DemoSandbox::LIFETIME_HOURS.' hours.';

    public function handle(DemoSandbox $sandbox): int
    {
        $this->info("Deleted {$sandbox->pruneExpired()} demo sandboxes.");

        return self::SUCCESS;
    }
}
