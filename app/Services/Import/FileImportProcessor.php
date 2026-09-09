<?php

namespace App\Services\Import;

use App\Jobs\ImportTaskList;
use App\Models\PendingImport;
use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\User;
use App\Services\Ai\SpreadsheetClassificationService;
use App\Services\DocumentFileExtractorService;
use App\Services\TaskListImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The source-agnostic core of "a file showed up somewhere Projector watches — turn it into
 * tasks/events/a document, or park it for a human to finish" — originally embedded directly in
 * ImportSlackFile, extracted so Slack and Dropbox (and any future source) call the exact same
 * classification/import logic instead of duplicating it. This class knows nothing about where
 * the file came from beyond $sourceLabel (used only for wording) — no Slack tokens, no Dropbox
 * tokens, no reply/notification mechanism. It receives an already-downloaded local file and
 * returns the plain-text message the caller should deliver however makes sense for that source
 * (in-channel reply, DM, email — see App\Services\Import\ImportNotifier).
 *
 * Two entirely different sources, chosen by extension:
 *
 * - Spreadsheet (csv/xlsx/xls/txt): reuses the exact pipeline the web Import Wizard's "smart"
 *   import already uses — TaskListImportService::analyze() parses the sheet,
 *   SpreadsheetClassificationService::classify() decides whether it's tasks, events, or a mix
 *   of both (one "pass" per record type it finds), and — PROVIDED every pass's mapping is one
 *   this project has already had a human confirm before (ProjectImportMapping) — imports
 *   immediately with no confirmation step.
 * - Document (docx): a Word document is prose, not rows and columns, so there's no mapping to
 *   recognize as "already confirmed" the way a spreadsheet's column layout can be. It can still
 *   skip the review queue when the uploader says explicitly what they want via a #tag or exact
 *   folder name (see ForcedTypeMatcher) — otherwise every readable document goes to the
 *   validation queue for a human to classify by hand.
 *
 * Three distinct reasons park a file as a PendingImport instead of auto-importing — visible on
 * the Import Wizard landing page for a human to resolve manually: a spreadsheet classification
 * couldn't confidently name even one column mapping at all; a spreadsheet classification could,
 * but the mapping is new for this project and hasn't been validated yet; or the file is a
 * document with no forced-type match, which needs a human to classify.
 */
class FileImportProcessor
{
    /**
     * Same row cap StoreTaskListImportRequest enforces for the web import — kept here too so a
     * huge file from any source doesn't tie up a queue worker for a genuinely unbounded amount
     * of time.
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

    public function __construct(
        private TaskListImportService $importService,
        private SpreadsheetClassificationService $classificationService,
        private DocumentFileExtractorService $extractor,
        private ForcedTypeMatcher $forcedTypeMatcher,
    ) {}

    /**
     * Lets a caller skip downloading a file at all when its extension could never be processed
     * — e.g. ImportSlackFile checks this before hitting Slack's API, as a cheap second line of
     * defense on top of whatever upstream filter (EventsController's own extension check, a
     * future Dropbox webhook handler's) already decided to dispatch the job in the first place.
     */
    public static function isSupportedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), [...self::SPREADSHEET_EXTENSIONS, ...self::DOCUMENT_EXTENSIONS], true);
    }

    /**
     * @param  list<string>  $tagSignals  Free text (filename, a Slack message, ...) checked for
     *                                    a #tag forcing a document type — see ForcedTypeMatcher.
     *                                    The filename itself only needs to be included here if
     *                                    the caller wants it checked; FileImportProcessor
     *                                    doesn't add it automatically, since not every source
     *                                    necessarily wants that (kept explicit rather than
     *                                    surprising).
     * @return string The plain-text message the caller should deliver — never contains any
     *                source-specific markup (e.g. Slack's `<url|text>` link syntax), just a
     *                bare URL where relevant, so it reads correctly whether it ends up posted
     *                in a Slack channel, DMed, or emailed.
     */
    public function process(
        Project $project,
        User $attributedTo,
        string $tmpPath,
        string $originalFilename,
        ?string $mimetype,
        array $tagSignals,
        string $sourceLabel,
        ?string $exactFolderName = null,
    ): ?string {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $isSpreadsheet = in_array($extension, self::SPREADSHEET_EXTENSIONS, true);
        $isDocument = in_array($extension, self::DOCUMENT_EXTENSIONS, true);

        if (! $isSpreadsheet && ! $isDocument) {
            // Callers are expected to have already filtered to these extensions before even
            // downloading the file — this is just a second, cheap line of defense, so it
            // returns nothing to report rather than manufacturing an error about a file type
            // nobody claimed to import.
            return null;
        }

        if ($isDocument) {
            return $this->processDocument($project, $attributedTo, $tmpPath, $originalFilename, $tagSignals, $exactFolderName, $sourceLabel);
        }

        return $this->processSpreadsheet($project, $attributedTo, $tmpPath, $originalFilename, $mimetype, $sourceLabel);
    }

    private function processSpreadsheet(Project $project, User $attributedTo, string $tmpPath, string $originalFilename, ?string $mimetype, string $sourceLabel): string
    {
        $uploadedFile = new UploadedFile($tmpPath, $originalFilename, $mimetype, null, true);
        $analysis = $this->importService->analyze($uploadedFile);

        if ($analysis['rows'] === []) {
            return "\"{$originalFilename}\" didn't have any rows to import.";
        }

        if (count($analysis['rows']) > self::MAX_ROWS) {
            return "\"{$originalFilename}\" has more than ".self::MAX_ROWS." rows — that's too many to import automatically. Try the Import Wizard in Projector instead.";
        }

        $usablePasses = $this->classifySpreadsheet($project, $analysis['headers'], $analysis['rows']);

        if ($usablePasses === []) {
            return $this->queueForValidation(
                $project,
                $attributedTo,
                $tmpPath,
                $originalFilename,
                'spreadsheet',
                $sourceLabel,
                'Uploaded via '.ucfirst($sourceLabel)." — couldn't automatically tell whether this is a task list, an event list, or which column has the name/title.",
                "Couldn't automatically tell how to import \"{$originalFilename}\" — added it to the review queue in Projector's Import Wizard: ".$this->importIndexUrl($project)
            );
        }

        $unconfirmedPasses = array_values(array_filter(
            $usablePasses,
            fn (array $pass) => ! ProjectImportMapping::isKnown($project, $pass['list_type'], $pass['mapping'])
        ));

        // Even one pass with a mapping this project hasn't confirmed before holds up the whole
        // file rather than auto-importing the known passes and queuing only the rest — a file
        // either imports cleanly on its own, or a human reviews all of it at once, never a
        // partial silent import alongside a partial queue.
        if ($unconfirmedPasses !== []) {
            return $this->queueForValidation(
                $project,
                $attributedTo,
                $tmpPath,
                $originalFilename,
                'spreadsheet',
                $sourceLabel,
                'Uploaded via '.ucfirst($sourceLabel)." — classified as {$this->passTypesSummary($usablePasses)}, but this column mapping hasn't been confirmed for this project before.",
            );
        }

        return $this->importSpreadsheetPasses($project, $attributedTo, $originalFilename, $usablePasses, $analysis['headers'], $analysis['rows']);
    }

    /**
     * @param  list<string>  $tagSignals
     */
    private function processDocument(Project $project, User $attributedTo, string $tmpPath, string $originalFilename, array $tagSignals, ?string $exactFolderName, string $sourceLabel): string
    {
        try {
            $text = trim(strip_tags($this->extractor->extractDocxHtml($tmpPath)));
        } catch (Throwable $e) {
            Log::warning('DocumentFileExtractorService::extractDocxHtml failed for an imported file', ['message' => $e->getMessage()]);

            return "Sorry, I couldn't read \"{$originalFilename}\" as a Word document.";
        }

        if ($text === '') {
            return "\"{$originalFilename}\" didn't have any content to import.";
        }

        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            return "\"{$originalFilename}\" is too long to import automatically. Try the Import Wizard in Projector instead.";
        }

        $forcedType = $this->forcedTypeMatcher->match($project, $tagSignals, $exactFolderName);

        if ($forcedType !== null) {
            $project->documents()->create([
                'type' => $forcedType->key,
                'name' => $originalFilename,
                'content' => $text,
                'creator_id' => $attributedTo->id,
            ]);

            $url = route('projects.show', $project);

            return "✅ Filed \"{$originalFilename}\" as {$forcedType->label} (tagged #{$forcedType->short_code}): {$url}";
        }

        return $this->queueForValidation(
            $project,
            $attributedTo,
            $tmpPath,
            $originalFilename,
            'text',
            $sourceLabel,
            'Uploaded via '.ucfirst($sourceLabel).' — a Word document needs a human to classify and confirm what it contains before it can be imported.',
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<array{list_type: string, mapping: array<string, string|null>}>
     */
    private function classifySpreadsheet(Project $project, array $headers, array $rows): array
    {
        try {
            $result = $this->classificationService->classify($headers, $rows, $project->client?->organization_id);
        } catch (Throwable $e) {
            Log::warning('SpreadsheetClassificationService::classify failed for an imported file', ['message' => $e->getMessage()]);

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
    private function importSpreadsheetPasses(Project $project, User $attributedTo, string $originalFilename, array $passes, array $headers, array $rows): string
    {
        $countsByType = ['task' => 0, 'event' => 0];

        foreach ($passes as $pass) {
            $isEvent = $pass['list_type'] === 'event';

            $importDocument = $project->documents()->create([
                'type' => $isEvent ? 'event_list_import' : 'task_list_import',
                'name' => $originalFilename,
                'content' => '[]',
                'creator_id' => $attributedTo->id,
                'metadata' => [
                    'original_filename' => $originalFilename,
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

        $url = route('projects.show', $project);

        return '✅ Imported '.implode(' and ', $parts)." from \"{$originalFilename}\": {$url}";
    }

    /**
     * @param  list<array{list_type: string, mapping: array<string, string|null>}>  $passes
     */
    private function passTypesSummary(array $passes): string
    {
        return implode(' and ', array_unique(array_map(fn (array $pass) => $pass['list_type'].'(s)', $passes)));
    }

    /**
     * Stores the already-downloaded file (still on disk at $tmpPath — the caller's
     * responsibility to clean up once processing returns, not this method's) as a PendingImport.
     * $replyText defaults to the standard "Document Placed In Validation Queue" wording used
     * for both the spreadsheet-mapping-not-yet-confirmed and document cases; the
     * couldn't-classify-at-all case overrides it since that has nothing to "confirm" so much as
     * to figure out from scratch.
     */
    private function queueForValidation(Project $project, User $attributedTo, string $tmpPath, string $originalFilename, string $sourceType, string $sourceLabel, string $note, ?string $replyText = null): string
    {
        $pendingImport = PendingImport::create([
            'project_id' => $project->id,
            'original_filename' => $originalFilename,
            'source_type' => $sourceType,
            'source' => $sourceLabel,
            'uploaded_by_user_id' => $attributedTo->id,
            'note' => $note,
        ]);

        $pendingImport->addMedia($tmpPath)->preservingOriginal()->toMediaCollection('file');

        $url = $this->importIndexUrl($project);

        return $replyText ?? "\"{$originalFilename}\" — Document Placed In Validation Queue. Click here to review: {$url}";
    }

    /**
     * Links to the Import Wizard with the uploading project's own organization pre-selected
     * (?org=) — without it, the link lands on whatever org happens to be active in the
     * clicker's browser session, which silently shows an empty "Needs Review" list if that
     * happens to be a different org than the one the file was actually uploaded into.
     */
    private function importIndexUrl(Project $project): string
    {
        return route('import.index', ['org' => $project->organization_id]);
    }
}
