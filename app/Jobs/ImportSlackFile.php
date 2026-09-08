<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\SlackPendingImport;
use App\Models\User;
use App\Services\Ai\SpreadsheetClassificationService;
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
 * message.channels/file_share handling). Reuses the exact pipeline the web Import Wizard's
 * "smart" import already uses: TaskListImportService::analyze() parses the sheet,
 * SpreadsheetClassificationService::classify() decides whether it's tasks, events, or a mix of
 * both (one "pass" per record type it finds) — same AI classification, no confirmation-modal
 * step, matching how /task and /events also skip any human-review step in favor of immediate
 * feedback in the channel, PROVIDED every pass's mapping is one this project has already had a
 * human confirm before (ProjectImportMapping). Two distinct reasons park a file as a
 * SlackPendingImport instead of auto-importing — visible on the Import Wizard landing page for
 * a human to resolve manually: classification couldn't confidently name even one column mapping
 * at all, or it could, but the mapping is new for this project and hasn't been validated yet.
 */
class ImportSlackFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Matches ImportTaskList's own timeout — this job dispatchSync()s that job (once per usable
     * pass) and waits for it to finish inline, so it needs at least as much headroom.
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

    public function handle(TaskListImportService $importService, SpreadsheetClassificationService $classificationService): void
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

            if ($analysis['rows'] === []) {
                $this->reply("\"{$this->slackFile['name']}\" didn't have any rows to import.");

                return;
            }

            if (count($analysis['rows']) > self::MAX_ROWS) {
                $this->reply("\"{$this->slackFile['name']}\" has more than ".self::MAX_ROWS.' rows — that\'s too many to import from Slack. Try the Import Wizard in Projector instead.');

                return;
            }

            $usablePasses = $this->classify($classificationService, $analysis['headers'], $analysis['rows']);

            if ($usablePasses === []) {
                $this->queueUnclassifiable($uploadedFile);

                return;
            }

            $unconfirmedPasses = array_values(array_filter(
                $usablePasses,
                fn (array $pass) => ! ProjectImportMapping::isKnown($this->project, $pass['list_type'], $pass['mapping'])
            ));

            // Even one pass with a mapping this project hasn't confirmed before holds up the
            // whole file rather than auto-importing the known passes and queuing only the rest
            // — a file either imports cleanly on its own, or a human reviews all of it at once,
            // never a partial silent import alongside a partial queue.
            if ($unconfirmedPasses !== []) {
                $this->queueForValidation($uploadedFile, $usablePasses);

                return;
            }

            $this->importPasses($usablePasses, $analysis['headers'], $analysis['rows']);
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<array{list_type: string, mapping: array<string, string|null>}>
     */
    private function classify(SpreadsheetClassificationService $classificationService, array $headers, array $rows): array
    {
        try {
            $result = $classificationService->classify($headers, $rows, $this->project->client?->organization_id);
        } catch (Throwable $e) {
            Log::warning('SpreadsheetClassificationService::classify failed for a Slack file upload', ['message' => $e->getMessage()]);

            return [];
        }

        // A pass with no name/title column mapped isn't something ImportTaskList can actually
        // do anything useful with (every row would just be silently skipped) — treated the same
        // as classification not proposing that pass at all.
        return array_values(array_filter(
            $result['passes'],
            fn (array $pass) => ($pass['mapping']['name'] ?? null) !== null
        ));
    }

    /**
     * @param  list<array{list_type: string, mapping: array<string, string|null>}>  $passes
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function importPasses(array $passes, array $headers, array $rows): void
    {
        $countsByType = ['task' => 0, 'event' => 0];

        foreach ($passes as $pass) {
            $isEvent = $pass['list_type'] === 'event';

            $importDocument = $this->project->documents()->create([
                'type' => $isEvent ? 'event_list_import' : 'task_list_import',
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

            ImportTaskList::dispatchSync($importDocument, $pass['list_type'], $headers, $rows, $pass['mapping']);

            $importDocument->refresh();
            $countsByType[$pass['list_type']] += $importDocument->metadata['created_count'] ?? 0;
        }

        $parts = array_filter([
            $countsByType['task'] > 0 ? "{$countsByType['task']} task(s)" : null,
            $countsByType['event'] > 0 ? "{$countsByType['event']} event(s)" : null,
        ]);

        $url = route('projects.show', $this->project);
        $this->reply('✅ Imported '.implode(' and ', $parts)." from \"{$this->slackFile['name']}\": {$url}");
    }

    private function queueUnclassifiable(UploadedFile $uploadedFile): void
    {
        $this->storeAsPendingImport($uploadedFile, "Uploaded via Slack — couldn't automatically tell whether this is a task list, an event list, or which column has the name/title.");

        $url = route('import.index');
        $this->reply("Couldn't automatically tell how to import \"{$this->slackFile['name']}\" — added it to the review queue in Projector's Import Wizard: {$url}");
    }

    /**
     * @param  list<array{list_type: string, mapping: array<string, string|null>}>  $passes
     */
    private function queueForValidation(UploadedFile $uploadedFile, array $passes): void
    {
        $types = implode(' and ', array_unique(array_map(fn (array $pass) => $pass['list_type'].'(s)', $passes)));
        $this->storeAsPendingImport($uploadedFile, "Uploaded via Slack — classified as {$types}, but this column mapping hasn't been confirmed for this project before.");

        $url = route('import.index');
        $this->reply("\"{$this->slackFile['name']}\" — Document Placed In Validation Queue. <{$url}|Click Here to Review>");
    }

    // Named to avoid colliding with Queueable::queue() (the trait Laravel's own dispatcher
    // calls to push this job onto its queue connection) — a same-named private method here
    // shadows it and breaks dispatch entirely, even though PHP's visibility rules would
    // normally let a private method share a name with an unrelated public one.
    private function storeAsPendingImport(UploadedFile $uploadedFile, string $note): void
    {
        $pendingImport = SlackPendingImport::create([
            'project_id' => $this->project->id,
            'original_filename' => $this->slackFile['name'],
            'uploaded_by_user_id' => $this->user->id,
            'note' => $note,
        ]);

        $pendingImport->addMedia($uploadedFile)->toMediaCollection('file');
    }

    private function reply(string $text): void
    {
        $response = Http::withToken($this->slackBotToken)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $this->slackChannelId,
            'text' => $text,
        ]);

        if ($response->json('ok') !== true) {
            Log::warning('Slack chat.postMessage failed for a file-triggered import', [
                'channel' => $this->slackChannelId,
                'error' => $response->json('error'),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ImportSlackFile failed: '.$exception->getMessage());

        $this->reply("Sorry, something went wrong importing \"{$this->slackFile['name']}\": {$exception->getMessage()}");
    }
}
