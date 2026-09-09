<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Services\Import\FileImportProcessor;
use App\Services\Import\ImportNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triggered by a file dropped straight into a bound Slack channel (EventsController's
 * message.channels/file_share handling). All of the actual classification/import decision-making
 * lives in App\Services\Import\FileImportProcessor (shared with every other import source, e.g.
 * Dropbox) — this job is now just the Slack-specific shell around it: downloading the file via
 * Slack's API, and delivering whatever message the processor produces back through Slack (an
 * in-channel reply, via App\Services\Import\ImportNotifier).
 */
class ImportSlackFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Matches ImportTaskList's own timeout — FileImportProcessor dispatchSync()s that job (once
     * per usable pass) and waits for it to finish inline, so this needs at least as much
     * headroom.
     */
    public int $timeout = 600;

    /**
     * @param  array{name: string, url_private_download: string, mimetype: string|null}  $slackFile  The
     *                                                                                               relevant subset of the Slack file object from the message event's `files` array —
     *                                                                                               passed as a plain array (not re-fetched via files.info) since the file_share
     *                                                                                               message event already carries everything this needs.
     * @param  string|null  $messageText  The text of the message the file was shared with, if
     *                                    any — checked (alongside the filename) for a #tag
     *                                    forcing a specific document type; see
     *                                    App\Services\Import\ForcedTypeMatcher.
     */
    public function __construct(
        public Project $project,
        public User $user,
        public array $slackFile,
        public string $slackBotToken,
        public string $slackChannelId,
        public ?string $messageText = null,
    ) {}

    public function handle(FileImportProcessor $processor, ImportNotifier $notifier): void
    {
        $extension = strtolower(pathinfo($this->slackFile['name'], PATHINFO_EXTENSION));

        if (! FileImportProcessor::isSupportedExtension($extension)) {
            // EventsController already filters to these extensions before dispatching — this
            // is just a second, cheap line of defense, so it fails silently rather than
            // confusing a channel with an error about a file type nobody claimed to import.
            return;
        }

        $download = Http::withToken($this->slackBotToken)->get($this->slackFile['url_private_download']);

        if ($download->failed()) {
            $this->reply($notifier, "Sorry, I couldn't download \"{$this->slackFile['name']}\" from Slack to import it.");

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'slack_import').'.'.$extension;
        file_put_contents($tmpPath, $download->body());

        try {
            /** @var list<string> $tagSignals */
            $tagSignals = array_values(array_filter([$this->slackFile['name'], $this->messageText]));

            $message = $processor->process(
                $this->project,
                $this->user,
                $tmpPath,
                $this->slackFile['name'],
                $this->slackFile['mimetype'] ?? null,
                $tagSignals,
                'slack',
            );

            if ($message !== null) {
                $this->reply($notifier, $message);
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    private function reply(ImportNotifier $notifier, string $message): void
    {
        $notifier->notify($this->user, $message, $this->project, [
            'bot_token' => $this->slackBotToken,
            'channel_id' => $this->slackChannelId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportSlackFile failed: '.$exception->getMessage());

        $this->reply(app(ImportNotifier::class), "Sorry, something went wrong importing \"{$this->slackFile['name']}\": {$exception->getMessage()}");
    }
}
