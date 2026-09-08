<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Services\TaskListImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triggered by a file dropped straight into a bound Slack channel (EventsController's
 * message.channels/file_share handling) — reuses the exact same parsing/creation pipeline the
 * web Import Wizard uses (TaskListImportService::analyze() + ImportTaskList), just without the
 * confirmation-modal step: the suggested column mapping is used as-is, matching how /task and
 * /events also skip any human-review step in favor of immediate feedback in the channel.
 */
class ImportEventsFromSlackFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Matches ImportTaskList's own timeout — this job dispatchSync()s that job and waits for
     * it to finish inline, so it needs at least as much headroom.
     */
    public int $timeout = 600;

    /**
     * Same row cap StoreTaskListImportRequest enforces for the web import — kept here too so a
     * huge file dropped in Slack doesn't tie up the queue for a genuinely unbounded amount of
     * time.
     */
    private const MAX_ROWS = 5000;

    /**
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * @param  array{name: string, url_private_download: string, mimetype: string|null}  $slackFile  The
     *                                                                                               relevant subset of the Slack file object from the message event's `files` array —
     *                                                                                               passed as a plain array (not re-fetched via files.info) since the file_share
     *                                                                                               message event already carries everything this needs.
     */
    public function __construct(
        public Project $project,
        public User $user,
        public array $slackFile,
        public string $slackBotToken,
        public string $slackChannelId,
    ) {}

    public function handle(TaskListImportService $importService): void
    {
        $extension = strtolower(pathinfo($this->slackFile['name'], PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            // EventsController already filters to these extensions before dispatching — this
            // is just a second, cheap line of defense, so it fails silently rather than
            // confusing a channel with an error about a file type nobody claimed to import.
            return;
        }

        $download = Http::withToken($this->slackBotToken)->get($this->slackFile['url_private_download']);

        if ($download->failed()) {
            $this->reply("Sorry, I couldn't download \"{$this->slackFile['name']}\" from Slack to import it.");

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'slack_import').'.'.$extension;
        file_put_contents($tmpPath, $download->body());

        try {
            $uploadedFile = new UploadedFile($tmpPath, $this->slackFile['name'], $this->slackFile['mimetype'] ?? null, null, true);
            $analysis = $importService->analyze($uploadedFile);
        } finally {
            @unlink($tmpPath);
        }

        if ($analysis['rows'] === []) {
            $this->reply("\"{$this->slackFile['name']}\" didn't have any rows to import.");

            return;
        }

        if (count($analysis['rows']) > self::MAX_ROWS) {
            $this->reply("\"{$this->slackFile['name']}\" has more than ".self::MAX_ROWS.' rows — that\'s too many to import from Slack. Try the Import Wizard in Projector instead.');

            return;
        }

        if ($analysis['suggested_mapping']['name'] === null) {
            $this->reply("Couldn't find a name/title column in \"{$this->slackFile['name']}\" — expected a header like \"Name\", \"Event\", or \"Title\".");

            return;
        }

        $importDocument = $this->project->documents()->create([
            'type' => 'event_list_import',
            'name' => $this->slackFile['name'],
            'content' => '[]',
            'creator_id' => $this->user->id,
            'metadata' => [
                'original_filename' => $this->slackFile['name'],
                'created_count' => 0,
                'skipped' => [],
                'status' => 'importing',
            ],
        ]);

        ImportTaskList::dispatchSync(
            $importDocument,
            'event',
            $analysis['headers'],
            $analysis['rows'],
            $analysis['suggested_mapping'],
        );

        $importDocument->refresh();
        $createdCount = $importDocument->metadata['created_count'] ?? 0;
        $skippedCount = count($importDocument->metadata['skipped'] ?? []);

        $url = route('projects.show', $this->project).'?tab=calendar';
        $message = $skippedCount === 0
            ? "✅ Imported {$createdCount} events from \"{$this->slackFile['name']}\": {$url}"
            : "✅ Imported {$createdCount} events from \"{$this->slackFile['name']}\" (skipped {$skippedCount} row(s)): {$url}";

        $this->reply($message);
    }

    private function reply(string $text): void
    {
        $response = Http::withToken($this->slackBotToken)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $this->slackChannelId,
            'text' => $text,
        ]);

        if ($response->json('ok') !== true) {
            Log::warning('Slack chat.postMessage failed for a file-triggered event import', [
                'channel' => $this->slackChannelId,
                'error' => $response->json('error'),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportEventsFromSlackFile failed: '.$exception->getMessage());

        $this->reply("Sorry, something went wrong importing \"{$this->slackFile['name']}\": {$exception->getMessage()}");
    }
}
