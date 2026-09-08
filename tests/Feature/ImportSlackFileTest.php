<?php

use App\Contracts\LlmDriver;
use App\Jobs\ImportSlackFile;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\SlackPendingImport;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);
    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'event',
        'label' => 'Event',
        'is_task' => false,
        'order' => 2,
    ]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function slackFilePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'export.csv',
        'url_private_download' => 'https://files.slack.com/files-pri/T123-F123/export.csv',
        'mimetype' => 'text/csv',
    ], $overrides);
}

/**
 * @param  array<int, array{list_type: string, mapping: array<string, string|null>}>  $passes
 * @return array{status: string, content: array{passes: array<int, array<string, mixed>>}}
 */
function classificationResult(array $passes): array
{
    return [
        'status' => 'success',
        'content' => [
            'passes' => array_map(fn (array $pass) => array_merge($pass, ['rationale' => 'test']), $passes),
        ],
    ];
}

function fakeClassification(array $passes): void
{
    test()->mock(LlmDriver::class)->shouldReceive('call')->once()->andReturn(classificationResult($passes));
}

const EMPTY_MAPPING = ['name' => null, 'priority' => null, 'task_status' => null, 'due_at' => null, 'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null];

/**
 * Builds a real, valid .docx file's raw bytes (via PhpWord, the same library
 * DocumentFileExtractorService reads with) — one paragraph per line of $text.
 *
 * @param  list<string>  $lines
 */
function createTestDocxBytes(array $lines): string
{
    $phpWord = new \PhpOffice\PhpWord\PhpWord;
    $section = $phpWord->addSection();
    foreach ($lines as $line) {
        $section->addText($line);
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'test_docx').'.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);
    $bytes = (string) file_get_contents($tmpPath);
    unlink($tmpPath);

    return $bytes;
}

// ── Successful classification (mapping already confirmed for this project) ─

it('imports events from a downloaded csv and posts a summary in the channel', function () {
    $csv = "Name,Start Date,Due Date,Tag\nTeam Offsite,2026-09-10,2026-09-10,offsite\nQuarterly Review,2026-09-15,,";
    $mapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'due_at' => 'Due Date', 'start_date' => 'Start Date', 'tag' => 'Tag']);
    ProjectImportMapping::record($this->project, 'event', $mapping);

    Http::fake([
        'files.slack.com/*' => Http::response($csv, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    fakeClassification([
        ['list_type' => 'event', 'mapping' => $mapping],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    $events = Document::where('project_id', $this->project->id)->where('type', 'event')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('name'))->toContain('Team Offsite', 'Quarterly Review');

    $importDocument = Document::where('project_id', $this->project->id)->where('type', 'event_list_import')->first();
    expect($importDocument)->not->toBeNull()
        ->and($importDocument->creator_id)->toBe($this->user->id)
        ->and($importDocument->metadata['created_count'])->toBe(2);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'C123'
            && str_contains($request['text'], 'Imported 2 event(s)');
    });
});

it('imports tasks from a downloaded csv when classified as a task list', function () {
    $csv = "Name,Assignee,Priority\nFollow up with client,Jane,high\nSend invoice,,low";
    $mapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'assignee' => 'Assignee', 'priority' => 'Priority']);
    ProjectImportMapping::record($this->project, 'task', $mapping);

    Http::fake([
        'files.slack.com/*' => Http::response($csv, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    fakeClassification([
        ['list_type' => 'task', 'mapping' => $mapping],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'tasks.csv']), 'xoxb-fake-token', 'C123');

    $tasks = Document::where('project_id', $this->project->id)->where('type', 'task')->get();

    expect($tasks)->toHaveCount(2)
        ->and(Document::where('project_id', $this->project->id)->where('type', 'task_list_import')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], 'Imported 2 task(s)'));
});

it('imports both tasks and events from a single file with a mixed classification', function () {
    $csv = "Name,Assignee,Start Date\nFollow up with client,Jane,\nTeam Offsite,,2026-09-10";
    $taskMapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'assignee' => 'Assignee']);
    $eventMapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'start_date' => 'Start Date']);
    ProjectImportMapping::record($this->project, 'task', $taskMapping);
    ProjectImportMapping::record($this->project, 'event', $eventMapping);

    Http::fake([
        'files.slack.com/*' => Http::response($csv, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    fakeClassification([
        ['list_type' => 'task', 'mapping' => $taskMapping],
        ['list_type' => 'event', 'mapping' => $eventMapping],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'task_list_import')->exists())->toBeTrue()
        ->and(Document::where('project_id', $this->project->id)->where('type', 'event_list_import')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && str_contains($request['text'], 'task(s)')
        && str_contains($request['text'], 'event(s)'));
});

// ── Mapping not yet confirmed for this project → queued for validation ─────

it('queues the file for validation when the mapping has never been confirmed for this project', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Name,Start Date\nTeam Offsite,2026-09-10", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    fakeClassification([
        ['list_type' => 'event', 'mapping' => array_merge(EMPTY_MAPPING, ['name' => 'Name', 'start_date' => 'Start Date'])],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0);

    $pending = SlackPendingImport::where('project_id', $this->project->id)->first();
    expect($pending)->not->toBeNull()
        ->and($pending->note)->toContain("hasn't been confirmed for this project before");

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && str_contains($request['text'], 'Document Placed In Validation Queue')
        && str_contains($request['text'], route('import.index')));
});

it('queues the whole file for validation when only one of several passes has an unconfirmed mapping', function () {
    $taskMapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'assignee' => 'Assignee']);
    $eventMapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'start_date' => 'Start Date']);
    // Only the task mapping has been confirmed before — the event mapping is new.
    ProjectImportMapping::record($this->project, 'task', $taskMapping);

    Http::fake([
        'files.slack.com/*' => Http::response("Name,Assignee,Start Date\nFollow up,Jane,\nTeam Offsite,,2026-09-10", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    fakeClassification([
        ['list_type' => 'task', 'mapping' => $taskMapping],
        ['list_type' => 'event', 'mapping' => $eventMapping],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'task')->count())->toBe(0)
        ->and(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(0)
        ->and(SlackPendingImport::where('project_id', $this->project->id)->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && str_contains($request['text'], 'Document Placed In Validation Queue'));
});

// ── Can't confidently classify → queued for review ──────────────────────────

it('queues the file for review when classification returns no passes', function () {
    Http::fake(['files.slack.com/*' => Http::response("Widget,Color\nfoo,red", 200)]);
    fakeClassification([]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0);

    $pending = SlackPendingImport::where('project_id', $this->project->id)->first();
    expect($pending)->not->toBeNull()
        ->and($pending->original_filename)->toBe('export.csv')
        ->and($pending->uploaded_by_user_id)->toBe($this->user->id)
        ->and($pending->getFirstMedia('file'))->not->toBeNull();
});

it('queues the file for review when no proposed pass has a usable name column', function () {
    Http::fake(['files.slack.com/*' => Http::response("Widget,Color\nfoo,red", 200)]);
    fakeClassification([
        ['list_type' => 'task', 'mapping' => EMPTY_MAPPING],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0)
        ->and(SlackPendingImport::where('project_id', $this->project->id)->exists())->toBeTrue();
});

it('queues the file for review when the classification call itself fails', function () {
    Http::fake(['files.slack.com/*' => Http::response("Name\nSomething", 200)]);
    test()->mock(LlmDriver::class)->shouldReceive('call')->once()->andReturn(['status' => 'error', 'message' => 'boom']);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(SlackPendingImport::where('project_id', $this->project->id)->exists())->toBeTrue();
});

it('replies pointing at the import wizard when a file is queued for review', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Widget,Color\nfoo,red", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);
    fakeClassification([]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && str_contains($request['text'], "Couldn't automatically tell how to import")
        && str_contains($request['text'], route('import.index')));
});

// ── Document source (.docx) → always queued for validation ─────────────────

it('queues a docx document for review after extracting its text, without classifying it', function () {
    $bytes = createTestDocxBytes(['Team Offsite on 2026-09-10.', 'Follow up with the client about the contract.']);

    Http::fake([
        'files.slack.com/*' => Http::response($bytes, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportSlackFile::dispatchSync(
        $this->project,
        $this->user,
        slackFilePayload(['name' => 'schedule.docx', 'url_private_download' => 'https://files.slack.com/files-pri/T123-F123/schedule.docx', 'mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
        'xoxb-fake-token',
        'C123',
    );

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0);

    $pending = SlackPendingImport::where('project_id', $this->project->id)->first();
    expect($pending)->not->toBeNull()
        ->and($pending->source_type)->toBe('text')
        ->and($pending->original_filename)->toBe('schedule.docx')
        ->and($pending->uploaded_by_user_id)->toBe($this->user->id)
        ->and($pending->note)->toContain('Word document')
        ->and($pending->getFirstMedia('file'))->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && str_contains($request['text'], 'Document Placed In Validation Queue')
        && str_contains($request['text'], route('import.index')));
});

it('never calls the LLM for a docx upload — classification only happens when a human opens the review modal', function () {
    $bytes = createTestDocxBytes(['Some content.']);

    Http::fake([
        'files.slack.com/*' => Http::response($bytes, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    test()->mock(LlmDriver::class)->shouldNotReceive('call');

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'schedule.docx']), 'xoxb-fake-token', 'C123');

    expect(SlackPendingImport::where('project_id', $this->project->id)->exists())->toBeTrue();
});

it('replies with an error and does not queue when the docx can\'t be read', function () {
    Http::fake([
        'files.slack.com/*' => Http::response('this is not a real docx file', 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'schedule.docx']), 'xoxb-fake-token', 'C123');

    expect(SlackPendingImport::count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "couldn't read"));
});

it('replies with an error and does not queue when the docx has no content', function () {
    $bytes = createTestDocxBytes([]);

    Http::fake([
        'files.slack.com/*' => Http::response($bytes, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'schedule.docx']), 'xoxb-fake-token', 'C123');

    expect(SlackPendingImport::count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "didn't have any content"));
});

// ── Hard failures (never queued) ─────────────────────────────────────────────

it('replies with an error and imports nothing when the download fails', function () {
    Http::fake([
        'files.slack.com/*' => Http::response('', 404),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0)
        ->and(SlackPendingImport::count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "couldn't download"));
});

it('replies with an error and imports nothing when no rows are found', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Name,Start Date\n", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0)
        ->and(SlackPendingImport::count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "didn't have any rows"));
});

it('ignores a file whose extension is not importable', function () {
    Http::fake();

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'notes.pdf']), 'xoxb-fake-token', 'C123');

    Http::assertNothingSent();
});

it('logs a warning without throwing when the summary chat.postMessage fails', function () {
    $mapping = array_merge(EMPTY_MAPPING, ['name' => 'Name', 'due_at' => 'Start Date']);
    ProjectImportMapping::record($this->project, 'event', $mapping);

    Http::fake([
        'files.slack.com/*' => Http::response("Name,Start Date\nTeam Offsite,2026-09-10", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200),
    ]);
    fakeClassification([
        ['list_type' => 'event', 'mapping' => $mapping],
    ]);

    ImportSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(1);
});
