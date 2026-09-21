<?php

use App\Contracts\LlmDriver;
use App\Jobs\GenerateReportFromSlackCommand;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SlackChannelBinding;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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

    $this->workspace = SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'team_id' => 'T123',
        'bot_access_token' => 'xoxb-test-token',
    ]);

    SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'channel_id' => 'C123',
        'project_id' => $this->project->id,
    ]);

    SlackUserIdentity::factory()->create([
        'user_id' => $this->user->id,
        'slack_team_id' => 'T123',
        'slack_user_id' => 'U123',
    ]);

    config(['services.slack.signing_secret' => 'fake-signing-secret']);
});

/**
 * @param  array<string, string>  $overrides
 */
function postReportCommand(array $overrides = []): \Illuminate\Testing\TestResponse
{
    $payload = array_merge([
        'command' => '/report',
        'text' => '',
        'team_id' => 'T123',
        'channel_id' => 'C123',
        'user_id' => 'U123',
        'response_url' => 'https://hooks.slack.com/commands/fake',
    ], $overrides);

    $timestamp = time();
    $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.json_encode($payload), 'fake-signing-secret');

    return test()->withHeaders(['X-Slack-Request-Timestamp' => (string) $timestamp, 'X-Slack-Signature' => $signature])
        ->postJson('/slack/commands', $payload);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createReportTask(string $name, array $attributes = []): Document
{
    $document = Document::create(array_merge([
        'project_id' => test()->project->id,
        'name' => $name,
        'type' => 'task',
        'content' => 'x',
        'due_at' => '2026-10-01',
        'processed_at' => now(),
    ], $attributes));

    // DocumentObserver defaults a fresh task's status to 'todo' on creation, so a different one
    // has to be applied by a separate update — see ReportControllerTest for the same note.
    if (isset($attributes['task_status'])) {
        $document->update(['task_status' => $attributes['task_status']]);
    }

    return $document;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createReportEvent(string $name, array $attributes = []): Document
{
    return Document::create(array_merge([
        'project_id' => test()->project->id,
        'name' => $name,
        'type' => 'event',
        'content' => 'x',
        'due_at' => now()->addDays(10)->toDateString(),
        'processed_at' => now(),
    ], $attributes));
}

/**
 * The filenames of every file the job has asked Slack to reserve an upload for, in order.
 *
 * @return list<string>
 */
function uploadedFilenames(): array
{
    return Http::recorded(fn ($request) => $request->url() === 'https://slack.com/api/files.getUploadURLExternal')
        ->map(fn (array $pair) => $pair[0]['filename'])
        ->values()
        ->all();
}

/**
 * The structured filters the AI hands back for a request — everything unmentioned stays empty.
 *
 * @param  array<string, mixed>  $overrides
 */
function mockReportInterpretation(array $overrides = []): void
{
    test()->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => array_merge([
            'assignees' => [],
            'statuses' => [],
            'priorities' => [],
            'tags' => [],
            'projects' => [],
            'date_from' => null,
            'date_to' => null,
            'date_kind' => null,
            'format' => null,
        ], $overrides)]);
}

function runReportJob(string $text): void
{
    GenerateReportFromSlackCommand::dispatchSync(test()->project, test()->user, test()->workspace, 'C123', $text, 'https://hooks.slack.com/commands/fake');
}

/**
 * The task names in the Excel file the job uploaded, in row order.
 *
 * @return list<string>
 */
function uploadedTaskNames(): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, uploadedReportBytes());
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
    unlink($tmp);

    // A report spanning sub-projects has an extra leading Project column, so find the name column
    // by its header rather than assuming a letter.
    $column = 'A';
    while ($sheet->getCell($column.'1')->getValue() !== 'Task Name') {
        $column++;
    }

    $names = [];
    for ($row = 2; $sheet->getCell($column.$row)->getValue() !== null; $row++) {
        $names[] = (string) $sheet->getCell($column.$row)->getValue();
    }

    return $names;
}

function uploadComment(): string
{
    $comment = '';

    Http::assertSent(function ($request) use (&$comment) {
        if ($request->url() === 'https://slack.com/api/files.completeUploadExternal') {
            $comment = $request['initial_comment'];

            return true;
        }

        return false;
    });

    return $comment;
}

function assertNoReportUploaded(): void
{
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'files.getUploadURLExternal'));
}

function assertReportReply(string $contains): void
{
    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/commands/fake'
        && $request['response_type'] === 'ephemeral'
        && str_contains($request['text'], $contains));
}

function fakeSlackFileUpload(): void
{
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/abc', 'file_id' => 'F123']),
        'files.slack.com/*' => Http::response('OK'),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
        'hooks.slack.com/*' => Http::response(['ok' => true]),
    ]);
}

/**
 * The raw bytes the job sent to Slack's upload URL — the first file by default, when a request
 * produced more than one.
 */
function uploadedReportBytes(int $index = 0): string
{
    $bodies = Http::recorded(fn ($request) => $request->url() === 'https://files.slack.com/upload/v1/abc')
        ->map(fn (array $pair) => $pair[0]->body())
        ->values();

    expect($bodies)->not->toBeEmpty();

    return (string) $bodies[$index];
}

// ── Command ─────────────────────────────────────────────────────────────────

it('acknowledges /report immediately and hands the raw text to the job', function () {
    Bus::fake();

    postReportCommand(['text' => "Jane's high priority tasks due next week as a PDF"])
        ->assertOk()
        ->assertJson(['response_type' => 'ephemeral', 'text' => '⏳ Generating report…']);

    Bus::assertDispatched(GenerateReportFromSlackCommand::class, fn (GenerateReportFromSlackCommand $job) => $job->project->is($this->project)
        && $job->user->is($this->user)
        && $job->workspace->is($this->workspace)
        && $job->channelId === 'C123'
        && $job->text === "Jane's high priority tasks due next week as a PDF"
        && $job->responseUrl === 'https://hooks.slack.com/commands/fake');
});

it('accepts /report with no text', function () {
    Bus::fake();

    postReportCommand()->assertOk()->assertJsonPath('text', '⏳ Generating report…');

    Bus::assertDispatched(GenerateReportFromSlackCommand::class, fn (GenerateReportFromSlackCommand $job) => $job->text === '');
});

it('rejects /report in an unbound channel', function () {
    Bus::fake();

    postReportCommand(['channel_id' => 'C-unbound'])
        ->assertOk()
        ->assertJsonFragment(['text' => "This channel isn't bound to a project yet — an org-admin can bind it from the organization's Configuration tab in Projector."]);

    Bus::assertNotDispatched(GenerateReportFromSlackCommand::class);
});

it('rejects /report from an unlinked Slack user', function () {
    Bus::fake();

    postReportCommand(['user_id' => 'U-unlinked'])->assertOk()->assertJsonPath('response_type', 'ephemeral');

    Bus::assertNotDispatched(GenerateReportFromSlackCommand::class);
});

it('rejects /report from a linked user who is not in the project\'s organization', function () {
    Bus::fake();

    $outsider = User::factory()->create();
    SlackUserIdentity::factory()->create(['user_id' => $outsider->id, 'slack_team_id' => 'T123', 'slack_user_id' => 'U-outsider']);

    postReportCommand(['user_id' => 'U-outsider'])
        ->assertOk()
        ->assertJsonFragment(['text' => "You don't have access to this project's reports."]);

    Bus::assertNotDispatched(GenerateReportFromSlackCommand::class);
});

// ── Job: no filters ─────────────────────────────────────────────────────────

it('uploads the full Excel report without calling the AI when there is no text', function () {
    $this->mock(LlmDriver::class)->shouldNotReceive('call');
    createReportTask('Ship the thing');
    fakeSlackFileUpload();

    runReportJob('');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/files.getUploadURLExternal'
        && $request->hasHeader('Authorization', 'Bearer xoxb-test-token')
        && $request['filename'] === 'test-project-task-report.xlsx');

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, uploadedReportBytes());
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
    unlink($tmp);

    expect($sheet->getCell('C1')->getValue())->toBe('Task Name')
        ->and($sheet->getCell('C2')->getValue())->toBe('Ship the thing')
        ->and($sheet->getCell('B2')->getValue())->toBe('10/01/2026');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/files.completeUploadExternal'
        && $request['channel_id'] === 'C123'
        && $request['files'][0]['id'] === 'F123'
        && str_contains($request['initial_comment'], 'Test Project')
        && str_contains($request['initial_comment'], $this->user->name)
        && ! str_contains($request['initial_comment'], 'Filters'));
});

it('tells the requester there is nothing to report instead of uploading an empty file', function () {
    fakeSlackFileUpload();

    runReportJob('');

    assertNoReportUploaded();
    assertReportReply('no tasks');
});

// ── Job: format ─────────────────────────────────────────────────────────────

it('uploads a CSV when the AI reads a CSV request', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation(['format' => 'csv']);

    runReportJob('everything as a csv');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/files.getUploadURLExternal'
        && $request['filename'] === 'test-project-task-report.csv');

    $csv = uploadedReportBytes();

    expect($csv)->toStartWith("Status,\"Due Date\",\"Task Name\",Assignee,Priority,Tags\n")
        ->and($csv)->toContain('10/01/2026')
        ->and($csv)->toContain('Ship the thing');
});

it('uploads a PDF when the AI reads a PDF request', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation(['format' => 'pdf']);

    runReportJob('give me a pdf');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/files.getUploadURLExternal'
        && $request['filename'] === 'test-project-task-report.pdf');

    expect(uploadedReportBytes())->toStartWith('%PDF');
});

it('defaults to Excel when the request names no format', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation();

    runReportJob('what is open');

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/files.getUploadURLExternal'
        && $request['filename'] === 'test-project-task-report.xlsx');
});

// ── Job: filters ────────────────────────────────────────────────────────────

it('sends the AI today\'s date and the project\'s people, statuses, tags, and sub-projects to choose from', function () {
    $this->travelTo('2026-09-21 10:00:00');
    $teammate = User::factory()->create(['first_name' => 'Penny', 'last_name' => 'Lane']);
    $this->org->users()->attach($teammate->id, ['role' => 'member']);
    $this->project->categories()->create(['name' => 'Marketing', 'color' => '#ff0000']);
    Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function (string $system, string $user) use ($teammate) {
            return str_contains($user, 'Today: 2026-09-21 (Monday)')
                && str_contains($user, $teammate->name)
                && str_contains($user, 'in_progress: In Progress')
                && str_contains($user, 'Marketing')
                && str_contains($user, 'Sub Project')
                && str_contains($user, 'Request: penny stuff');
        })
        ->andReturn(['status' => 'success', 'content' => ['assignees' => [], 'statuses' => [], 'priorities' => [], 'tags' => [], 'projects' => [], 'date_from' => null, 'date_to' => null, 'date_kind' => null, 'format' => null]]);

    createReportTask('Ship the thing');
    fakeSlackFileUpload();

    runReportJob('penny stuff');
});

it('filters by assignee, status, and priority and says which filters it applied', function () {
    $penny = User::factory()->create(['first_name' => 'Penny', 'last_name' => 'Lane']);
    $this->org->users()->attach($penny->id, ['role' => 'member']);

    createReportTask('Penny in progress high', ['assignee_id' => $penny->id, 'task_status' => 'in_progress', 'priority' => 'high']);
    createReportTask('Penny in progress low', ['assignee_id' => $penny->id, 'task_status' => 'in_progress', 'priority' => 'low']);
    createReportTask('Penny todo high', ['assignee_id' => $penny->id, 'priority' => 'high']);
    createReportTask('Someone else in progress high', ['assignee_id' => $this->user->id, 'task_status' => 'in_progress', 'priority' => 'high']);
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['penny lane'], 'statuses' => ['in_progress'], 'priorities' => ['high']]);

    runReportJob("penny's high priority tasks that are in progress");

    expect(uploadedTaskNames())->toBe(['Penny in progress high'])
        ->and(uploadComment())->toContain('Filters: Assignee: '.$penny->name.' · Status: In Progress · Priority: High');
});

it('resolves "me" to the person who ran the command', function () {
    createReportTask('Mine', ['assignee_id' => $this->user->id]);
    createReportTask('Not mine');
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['me']]);

    runReportJob('my tasks');

    expect(uploadedTaskNames())->toBe(['Mine'])
        ->and(uploadComment())->toContain('Assignee: '.$this->user->name);
});

it('filters to unassigned tasks', function () {
    createReportTask('Mine', ['assignee_id' => $this->user->id]);
    createReportTask('Nobody');
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['Unassigned']]);

    runReportJob('unassigned tasks');

    expect(uploadedTaskNames())->toBe(['Nobody']);
});

it('filters by a due date range', function () {
    createReportTask('In range', ['due_at' => '2026-09-15']);
    createReportTask('Too early', ['due_at' => '2026-08-31']);
    createReportTask('Too late', ['due_at' => '2026-10-01']);
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'date_kind' => 'due']);

    runReportJob('due in september');

    expect(uploadedTaskNames())->toBe(['In range'])
        ->and(uploadComment())->toContain('Due 09/01/2026 – 09/30/2026');
});

it('filters by when tasks were completed, not when they were due', function () {
    $done = createReportTask('Finished last week', ['due_at' => '2099-01-01']);
    $done->update(['task_status' => 'done']);
    // The observer stamps "now" on a status change, so the completion date has to be set raw.
    Document::query()->whereKey($done->id)->toBase()->update(['status_changed_at' => '2026-09-16 12:00:00']);
    createReportTask('Due last week but not done', ['due_at' => '2026-09-16']);
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => '2026-09-14', 'date_to' => '2026-09-20', 'date_kind' => 'done']);

    runReportJob('what got done last week');

    expect(uploadedTaskNames())->toBe(['Finished last week'])
        ->and(uploadComment())->toContain('Done 09/14/2026 – 09/20/2026');
});

it('describes an open-ended date range', function (?string $from, ?string $to, string $expected) {
    createReportTask('A task', ['due_at' => '2026-09-15']);
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => $from, 'date_to' => $to]);

    runReportJob('some dates');

    expect(uploadComment())->toContain($expected);
})->with([
    'from only' => ['2026-09-01', null, 'Due on or after 09/01/2026'],
    'to only' => [null, '2026-09-30', 'Due on or before 09/30/2026'],
    'single day' => ['2026-09-15', '2026-09-15', 'Due on 09/15/2026'],
]);

it('filters by tag, including tasks with none', function () {
    $marketing = $this->project->categories()->create(['name' => 'Marketing', 'color' => '#ff0000']);
    createReportTask('Tagged')->categories()->sync([$marketing->id]);
    createReportTask('Untagged');
    fakeSlackFileUpload();
    mockReportInterpretation(['tags' => ['marketing']]);

    runReportJob('marketing tasks');

    expect(uploadedTaskNames())->toBe(['Tagged'])
        ->and(uploadComment())->toContain('Tag: Marketing');
});

it('filters by sub-project', function () {
    $sub = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
    createReportTask('Parent task');
    Document::create(['project_id' => $sub->id, 'name' => 'Sub task', 'type' => 'task', 'content' => 'x', 'processed_at' => now()]);
    fakeSlackFileUpload();
    mockReportInterpretation(['projects' => ['sub project']]);

    runReportJob('just the sub project');

    expect(uploadedTaskNames())->toBe(['Sub task']);
});

// ── Job: requests it can't honor ────────────────────────────────────────────

it('refuses to generate a report when it cannot find a person the request named', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['Janet Nobody']]);

    runReportJob("janet's tasks");

    assertNoReportUploaded();
    assertReportReply('a person named "Janet Nobody"');
});

it('refuses to generate a report for a status the project does not have', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation(['statuses' => ['blocked']]);

    runReportJob('blocked tasks');

    assertNoReportUploaded();
    assertReportReply('a status "blocked"');
});

it('refuses to generate a report for an impossible date', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => '2026-02-30']);

    runReportJob('february 30th');

    assertNoReportUploaded();
    assertReportReply('the date "2026-02-30"');
});

it('reports when nothing matched the filters instead of uploading an empty file', function () {
    createReportTask('Ship the thing', ['due_at' => '2026-10-01']);
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

    runReportJob('january');

    assertNoReportUploaded();
    assertReportReply('No tasks matched: Due 01/01/2026 – 01/31/2026');
});

it('does not fall back to the full report when the AI call fails', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'model unavailable']);

    runReportJob('penny stuff');

    assertNoReportUploaded();
    assertReportReply("couldn't work out what report you meant");
});

// ── Job: Slack failures ─────────────────────────────────────────────────────

it('explains how to fix a workspace that was connected before files:write existed', function () {
    createReportTask('Ship the thing');
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => false, 'error' => 'missing_scope']),
        'hooks.slack.com/*' => Http::response(['ok' => true]),
    ]);

    $job = new GenerateReportFromSlackCommand($this->project, $this->user, $this->workspace, 'C123', '', 'https://hooks.slack.com/commands/fake');

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $job->failed($e);
    }

    assertReportReply('reconnect Slack');
});

it('asks for the bot to be invited when it is not in the channel', function () {
    createReportTask('Ship the thing');
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/abc', 'file_id' => 'F123']),
        'files.slack.com/*' => Http::response('OK'),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => false, 'error' => 'not_in_channel']),
        'hooks.slack.com/*' => Http::response(['ok' => true]),
    ]);

    $job = new GenerateReportFromSlackCommand($this->project, $this->user, $this->workspace, 'C123', '', 'https://hooks.slack.com/commands/fake');

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $job->failed($e);
    }

    assertReportReply('/invite @Projector');
});

// ── Job: event calendars ────────────────────────────────────────────────────

it('sends a PDF event calendar by default when the request says events', function () {
    createReportEvent('Launch party');
    createReportTask('A task, not an event');
    fakeSlackFileUpload();
    mockReportInterpretation();

    runReportJob('all Events');

    expect(uploadedFilenames())->toBe(['test-project-calendar.pdf'])
        ->and(uploadedReportBytes())->toStartWith('%PDF')
        ->and(uploadComment())->toContain('📅 Event calendar for *Test Project*');
});

it('sends the calendar as CSV or Excel when asked, with only the events on it', function (string $format, string $extension) {
    createReportEvent('Launch party');
    createReportTask('A task, not an event');
    fakeSlackFileUpload();
    mockReportInterpretation(['format' => $format]);

    runReportJob("events as {$format}");

    expect(uploadedFilenames())->toBe(["test-project-calendar.{$extension}"]);

    if ($format === 'csv') {
        expect(uploadedReportBytes())->toContain('Launch party')
            ->and(uploadedReportBytes())->not->toContain('A task, not an event');
    } else {
        expect(uploadedReportBytes())->toStartWith('PK');
    }
})->with([
    'csv' => ['csv', 'csv'],
    'excel' => ['excel', 'xlsx'],
]);

it('still makes the task report when the request says tasks', function () {
    createReportEvent('Launch party');
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation();

    runReportJob('all tasks');

    expect(uploadedFilenames())->toBe(['test-project-task-report.xlsx'])
        ->and(uploadedTaskNames())->toBe(['Ship the thing']);
});

it('makes both reports when the request says tasks and events', function () {
    createReportEvent('Launch party');
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation();

    runReportJob('tasks and events');

    expect(uploadedFilenames())->toBe(['test-project-task-report.xlsx', 'test-project-calendar.pdf']);
});

it('filters the calendar by tag', function () {
    $marketing = $this->project->categories()->create(['name' => 'Marketing', 'color' => '#ff0000']);
    createReportEvent('Tagged event')->categories()->sync([$marketing->id]);
    createReportEvent('Untagged event');
    fakeSlackFileUpload();
    mockReportInterpretation(['tags' => ['marketing'], 'format' => 'csv']);

    runReportJob('marketing events csv');

    expect(uploadedReportBytes())->toContain('Tagged event')
        ->and(uploadedReportBytes())->not->toContain('Untagged event')
        ->and(uploadComment())->toContain('Filters: Tag: Marketing');
});

it('filters the calendar by an explicit date range, even one in the past', function () {
    createReportEvent('March event', ['due_at' => '2026-03-10']);
    createReportEvent('April event', ['due_at' => '2026-04-10']);
    createReportEvent('Upcoming event');
    fakeSlackFileUpload();
    mockReportInterpretation(['date_from' => '2026-03-01', 'date_to' => '2026-03-31', 'format' => 'csv']);

    runReportJob('events in march');

    expect(uploadedReportBytes())->toContain('March event')
        ->and(uploadedReportBytes())->not->toContain('April event')
        ->and(uploadedReportBytes())->not->toContain('Upcoming event')
        ->and(uploadComment())->toContain('Due 03/01/2026 – 03/31/2026');
});

it('filters the calendar by sub-project', function () {
    $sub = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
    createReportEvent('Parent event');
    createReportEvent('Sub event', ['project_id' => $sub->id]);
    fakeSlackFileUpload();
    mockReportInterpretation(['projects' => ['sub project'], 'format' => 'csv']);

    runReportJob('events for the sub project');

    expect(uploadedReportBytes())->toContain('Sub event')
        ->and(uploadedReportBytes())->not->toContain('Parent event');
});

it('refuses an events-only request filtered by something events do not have', function () {
    createReportEvent('Launch party');
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['me'], 'statuses' => ['todo']]);

    runReportJob('my events that are todo');

    assertNoReportUploaded();
    assertReportReply('I can\'t filter events by assignee, status');
});

it('applies task-only filters to the tasks and not the events when both are requested', function () {
    createReportEvent('Launch party');
    createReportTask('Mine', ['assignee_id' => $this->user->id]);
    createReportTask('Not mine');
    fakeSlackFileUpload();
    mockReportInterpretation(['assignees' => ['me']]);

    runReportJob('my tasks and events');

    expect(uploadedFilenames())->toBe(['test-project-task-report.xlsx', 'test-project-calendar.pdf'])
        ->and(uploadedTaskNames())->toBe(['Mine']);
});

it('says so when there are no events to put on a calendar', function () {
    createReportTask('Ship the thing');
    fakeSlackFileUpload();
    mockReportInterpretation();

    runReportJob('events');

    assertNoReportUploaded();
    assertReportReply('no upcoming events');
});
