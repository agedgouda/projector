<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\SlackPendingImport;
use App\Models\User;
use App\Services\Ai\SpreadsheetClassificationService;
use App\Services\DocumentFileExtractorService;
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
 * message.channels/file_share handling). Two entirely different sources, chosen by extension:
 *
 * - Spreadsheet (csv/xlsx/xls/txt): reuses the exact pipeline the web Import Wizard's "smart"
 *   import already uses — TaskListImportService::analyze() parses the sheet,
 *   SpreadsheetClassificationService::classify() decides whether it's tasks, events, or a mix
 *   of both (one "pass" per record type it finds), and — PROVIDED every pass's mapping is one
 *   this project has already had a human confirm before (ProjectImportMapping) — imports
 *   immediately with no confirmation step, matching how /task and /events also skip any
 *   human-review step in favor of immediate feedback in the channel.
 * - Document (docx): a Word document is prose, not rows and columns, so there's no mapping to
 *   recognize as "already confirmed" the way a spreadsheet's column layout can be — every
 *   document-sourced upload goes to the validation queue below for a human to classify by hand,
 *   the same review step TextExtractionService/ExtractTextRecords already do for a manually
 *   uploaded plain-text/markdown "smart" import on the web.
 *
 * Three distinct reasons park a file as a SlackPendingImport instead of auto-importing —
 * visible on the Import Wizard landing page for a human to resolve manually: a spreadsheet
 * classification couldn't confidently name even one column mapping at all; a spreadsheet
 * classification could, but the mapping is new for this project and hasn't been validated yet;
 * or the file is a document, which always needs a human to classify.
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
     * Matches ApplyTextImportTransformationRequest/classifyText's own 100,000-char cap for a
     * text source on the web.
     */
    private const MAX_TEXT_LENGTH = 100000;

    /**
     * @var list<string>
     */
    private const SPREADSHEET_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * @var list<string>
     */
    private const DOCUMENT_EXTENSIONS = ['docx'];

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

    public function handle(TaskListImportService $importService, SpreadsheetClassificationService $classificationService, DocumentFileExtractorService $extractor): void
    {
        $extension = strtolower(pathinfo($this->slackFile['name'], PATHINFO_EXTENSION));
        $isSpreadsheet = in_array($extension, self::SPREADSHEET_EXTENSIONS, true);
        $isDocument = in_array($extension, self::DOCUMENT_EXTENSIONS, true);

        if (! $isSpreadsheet && ! $isDocument) {
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
            if ($isDocument) {
                $this->handleDocumentSource($tmpPath, $extractor);
            } else {
                $this->handleSpreadsheetSource($tmpPath, $importService, $classificationService);
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    private function handleSpreadsheetSource(string $tmpPath, TaskListImportService $importService, SpreadsheetClassificationService $classificationService): void
    {
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

        $usablePasses = $this->classifySpreadsheet($classificationService, $analysis['headers'], $analysis['rows']);

        if ($usablePasses === []) {
            $this->queueUnclassifiable($tmpPath);

            return;
        }

        $unconfirmedPasses = array_values(array_filter(
            $usablePasses,
            fn (array $pass) => ! ProjectImportMapping::isKnown($this->project, $pass['list_type'], $pass['mapping'])
        ));

        // Even one pass with a mapping this project hasn't confirmed before holds up the whole
        // file rather than auto-importing the known passes and queuing only the rest — a file
        // either imports cleanly on its own, or a human reviews all of it at once, never a
        // partial silent import alongside a partial queue.
        if ($unconfirmedPasses !== []) {
            $this->queueForValidation($tmpPath, 'spreadsheet', "Uploaded via Slack — classified as {$this->passTypesSummary($usablePasses)}, but this column mapping hasn't been confirmed for this project before.");

            return;
        }

        $this->importSpreadsheetPasses($usablePasses, $analysis['headers'], $analysis['rows']);
    }

    /**
     * A Word document is prose, not a structured column layout — there's no equivalent to a
     * spreadsheet's "this exact mapping was already confirmed for this project" signal (the
     * same real-world content essentially never recurs verbatim), so unlike the spreadsheet
     * path this never attempts to classify-and-auto-import; every readable document always goes
     * to the validation queue for a human to classify by hand.
     */
    private function handleDocumentSource(string $tmpPath, DocumentFileExtractorService $extractor): void
    {
        try {
            $text = trim(strip_tags($extractor->extractDocxHtml($tmpPath)));
        } catch (Throwable $e) {
            Log::warning('DocumentFileExtractorService::extractDocxHtml failed for a Slack file upload', ['message' => $e->getMessage()]);
            $this->reply("Sorry, I couldn't read \"{$this->slackFile['name']}\" as a Word document.");

            return;
        }

        if ($text === '') {
            $this->reply("\"{$this->slackFile['name']}\" didn't have any content to import.");

            return;
        }

        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            $this->reply("\"{$this->slackFile['name']}\" is too long to import from Slack. Try the Import Wizard in Projector instead.");

            return;
        }

        $this->queueForValidation($tmpPath, 'text', 'Uploaded via Slack — a Word document needs a human to classify and confirm what it contains before it can be imported.');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<array{list_type: string, mapping: array<string, string|null>}>
     */
    private function classifySpreadsheet(SpreadsheetClassificationService $classificationService, array $headers, array $rows): array
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
    private function importSpreadsheetPasses(array $passes, array $headers, array $rows): void
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

    /**
     * @param  list<array{list_type: string, mapping: array<string, string|null>}>  $passes
     */
    private function passTypesSummary(array $passes): string
    {
        return implode(' and ', array_unique(array_map(fn (array $pass) => $pass['list_type'].'(s)', $passes)));
    }

    private function queueUnclassifiable(string $tmpPath): void
    {
        $this->queueForValidation($tmpPath, 'spreadsheet', "Uploaded via Slack — couldn't automatically tell whether this is a task list, an event list, or which column has the name/title.", "Couldn't automatically tell how to import \"{$this->slackFile['name']}\" — added it to the review queue in Projector's Import Wizard: ".route('import.index'));
    }

    /**
     * Stores the already-downloaded file (still on disk at $tmpPath — cleaned up by handle()'s
     * finally block once this returns, not here) as a SlackPendingImport, then replies in the
     * channel. $replyText defaults to the standard "Document Placed In Validation Queue" wording
     * used for both the spreadsheet-mapping-not-yet-confirmed and document cases;
     * queueUnclassifiable() overrides it since that case has nothing to "confirm" so much as to
     * figure out from scratch.
     */
    private function queueForValidation(string $tmpPath, string $sourceType, string $note, ?string $replyText = null): void
    {
        $pendingImport = SlackPendingImport::create([
            'project_id' => $this->project->id,
            'original_filename' => $this->slackFile['name'],
            'source_type' => $sourceType,
            'uploaded_by_user_id' => $this->user->id,
            'note' => $note,
        ]);

        $pendingImport->addMedia($tmpPath)->preservingOriginal()->toMediaCollection('file');

        $url = route('import.index');
        $this->reply($replyText ?? "\"{$this->slackFile['name']}\" — Document Placed In Validation Queue. <{$url}|Click Here to Review>");
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
