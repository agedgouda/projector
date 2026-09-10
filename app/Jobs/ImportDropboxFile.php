<?php

namespace App\Jobs;

use App\Models\DropboxWorkspace;
use App\Models\Project;
use App\Services\Dropbox\DropboxApiClient;
use App\Services\Import\FileImportProcessor;
use App\Services\Import\ImportNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triggered by a file appearing in a bound Dropbox folder (Dropbox\EventsController's webhook
 * handling). Dropbox's counterpart to ImportSlackFile — thinner, since there's no per-uploader
 * identity to resolve (see App\Models\DropboxWorkspace's own docblock for why: neither Dropbox's
 * webhook payload nor its files/list_folder response reliably names who added a given file
 * outside a Business/Team plan with audit-log file tracking enabled, so every import through a
 * bound folder attributes to whoever connected the account instead) and no in-channel reply
 * mechanism (a folder isn't a place to post a message — see App\Services\Import\ImportNotifier,
 * which falls back to a Slack DM or email instead).
 *
 * Uses the exact same App\Services\Import\FileImportProcessor as ImportSlackFile — the only
 * source-specific work here is downloading the file via Dropbox's API and figuring out
 * $subfolderName (the immediate subfolder under the bound folder the file landed in, if any,
 * for ForcedTypeMatcher's exact-folder-name signal).
 */
class ImportDropboxFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Matches ImportSlackFile's own timeout, for the same reason (FileImportProcessor
     * dispatchSync()s ImportTaskList inline and waits for it to finish).
     */
    public int $timeout = 600;

    public function __construct(
        public Project $project,
        public DropboxWorkspace $workspace,
        public string $dropboxPath,
        public string $filename,
        public ?string $subfolderName = null,
    ) {}

    public function handle(DropboxApiClient $client, FileImportProcessor $processor, ImportNotifier $notifier): void
    {
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));

        if (! FileImportProcessor::isSupportedExtension($extension)) {
            // Dropbox\EventsController already filters to these extensions before dispatching —
            // this is just a second, cheap line of defense, matching ImportSlackFile's own.
            return;
        }

        $attributedTo = $this->workspace->installedBy;

        if ($attributedTo === null) {
            Log::warning('ImportDropboxFile: workspace has no installed_by_user_id to attribute the import to', ['workspace_id' => $this->workspace->id]);

            return;
        }

        // A large spreadsheet can take a while to download and classify (FileImportProcessor
        // dispatches ImportTaskList synchronously and waits for it) — without this, the only
        // notification is the final result, so a file that's actually being worked on looks
        // identical to one that was silently dropped.
        $notifier->notify($attributedTo, "📥 Importing \"{$this->filename}\" from Dropbox…", $this->project);

        try {
            $bytes = $client->download($this->workspace, $this->dropboxPath);
        } catch (Throwable $e) {
            Log::warning('DropboxApiClient::download failed for an imported file', ['message' => $e->getMessage()]);

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'dropbox_import').'.'.$extension;
        file_put_contents($tmpPath, $bytes);

        try {
            $message = $processor->process(
                $this->project,
                $attributedTo,
                $tmpPath,
                $this->filename,
                null,
                [$this->filename],
                'dropbox',
                $this->subfolderName,
            );

            if ($message !== null) {
                $notifier->notify($attributedTo, $message, $this->project);
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportDropboxFile failed: '.$exception->getMessage());

        $attributedTo = $this->workspace->installedBy;

        if ($attributedTo !== null) {
            app(ImportNotifier::class)->notify(
                $attributedTo,
                "Sorry, something went wrong importing \"{$this->filename}\": {$exception->getMessage()}",
                $this->project,
            );
        }
    }
}
