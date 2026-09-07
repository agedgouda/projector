<?php

use App\Contracts\LlmDriver;
use App\Jobs\CreateEventFromSlackCommand;
use App\Jobs\CreateTaskFromSlackCommand;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
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
    ]);

    $this->binding = SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'channel_id' => 'C123',
        'project_id' => $this->project->id,
    ]);

    $this->identity = SlackUserIdentity::factory()->create([
        'user_id' => $this->user->id,
        'slack_team_id' => 'T123',
        'slack_user_id' => 'U123',
    ]);

    config(['services.slack.signing_secret' => 'fake-signing-secret']);
});

function signSlackCommandRequest(string $body, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fake-signing-secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

/**
 * @param  array<string, string>  $overrides
 */
function slackCommandPayload(array $overrides = []): array
{
    return array_merge([
        'command' => '/task',
        'text' => 'follow up with the client',
        'team_id' => 'T123',
        'channel_id' => 'C123',
        'user_id' => 'U123',
        'response_url' => 'https://hooks.slack.com/commands/fake',
    ], $overrides);
}

/**
 * The signature only depends on raw body bytes, not encoding — so signing the same JSON body
 * postJson() will send (rather than fighting Laravel's test client over raw form-encoding) still
 * faithfully exercises signature verification and the controller's $request->input() reads,
 * which are themselves encoding-agnostic.
 */
function postSlackCommand(array $payload): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders(signSlackCommandRequest(json_encode($payload)))
        ->postJson('/slack/commands', $payload);
}

// ── Signature ────────────────────────────────────────────────────────────────

it('rejects an unsigned request', function () {
    $this->postJson('/slack/commands', slackCommandPayload())->assertUnauthorized();
});

// ── Controller dispatch ─────────────────────────────────────────────────────

it('dispatches the task creation job and acknowledges immediately', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload())
        ->assertOk()
        ->assertJson(['response_type' => 'ephemeral', 'text' => '⏳ Creating task…']);

    Bus::assertDispatched(CreateTaskFromSlackCommand::class, function (CreateTaskFromSlackCommand $job) {
        return $job->project->is($this->project)
            && $job->user->is($this->user)
            && $job->text === 'follow up with the client'
            && $job->responseUrl === 'https://hooks.slack.com/commands/fake';
    });
});

it('rejects blank text with a usage message', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['text' => '']))
        ->assertOk()
        ->assertJsonPath('response_type', 'ephemeral')
        ->assertJsonFragment(['text' => 'Usage: `/task <description>` — e.g. `/task follow up with the client about the contract by Friday`.']);

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('rejects an unbound channel', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['channel_id' => 'C-unbound']))
        ->assertOk()
        ->assertJsonFragment(['text' => "This channel isn't bound to a project yet — an org-admin can bind it from the organization's Configuration tab in Projector."]);

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('rejects an unlinked slack user', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['user_id' => 'U-unlinked']))
        ->assertOk()
        ->assertJsonPath('response_type', 'ephemeral');

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('rejects an unknown slash command', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['command' => '/foo']))
        ->assertOk()
        ->assertJsonFragment(['text' => 'Unknown command.']);

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
    Bus::assertNotDispatched(CreateEventFromSlackCommand::class);
});

it('dispatches the event creation job and acknowledges immediately', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['command' => '/events', 'text' => 'team offsite next Thursday']))
        ->assertOk()
        ->assertJson(['response_type' => 'ephemeral', 'text' => '⏳ Creating event…']);

    Bus::assertDispatched(CreateEventFromSlackCommand::class, function (CreateEventFromSlackCommand $job) {
        return $job->project->is($this->project)
            && $job->user->is($this->user)
            && $job->text === 'team offsite next Thursday'
            && $job->responseUrl === 'https://hooks.slack.com/commands/fake';
    });
});

it('rejects blank text for /events with a usage message', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['command' => '/events', 'text' => '']))
        ->assertOk()
        ->assertJsonFragment(['text' => 'Usage: `/events <description>` — e.g. `/events team offsite next Thursday`.']);

    Bus::assertNotDispatched(CreateEventFromSlackCommand::class);
});

it('rejects an unlinked slack user for /events, mentioning events not tasks', function () {
    Bus::fake();

    postSlackCommand(slackCommandPayload(['command' => '/events', 'user_id' => 'U-unlinked']))
        ->assertOk()
        ->assertJsonFragment(['text' => 'Connect your Slack account in Projector first, so events you create are attributed to you: '.route('integrations.edit')]);

    Bus::assertNotDispatched(CreateEventFromSlackCommand::class);
});

// ── Job: extraction rule source ─────────────────────────────────────────────

it('uses the slack_task_extraction AiTemplate\'s user_prompt as the extraction rule', function () {
    AiTemplate::where('type', 'slack_task_extraction')
        ->update(['user_prompt' => 'CUSTOM RULE MARKER: extract only a title.']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, 'CUSTOM RULE MARKER'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Task', 'priority' => null, 'task_status' => null, 'due_at' => null,
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'anything', 'https://hooks.slack.com/commands/fake');
});

it('appends a roster of the org\'s users and pending invitations to the extraction rule', function () {
    OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'first_name' => 'Penny',
        'last_name' => 'Fitzgerald',
        'token' => str_repeat('a', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function ($system, $user) {
            return str_contains($user, 'Known people who can be assigned tasks on this project:')
                && str_contains($user, $this->user->name)
                && str_contains($user, 'Penny Fitzgerald');
        })
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Task', 'priority' => null, 'task_status' => null, 'due_at' => null,
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'anything', 'https://hooks.slack.com/commands/fake');
});

it('resolves an assignee mentioned by first name against the roster', function () {
    $this->org->users()->attach(User::factory()->create(['first_name' => 'Penny', 'last_name' => 'Fitzgerald'])->id, ['role' => 'team-member']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            // Simulates the AI having matched "Penny" against the roster and returned her full name.
            'name' => 'Add cheese to the keyword list', 'priority' => null, 'task_status' => null,
            'due_at' => null, 'assignee' => 'Penny Fitzgerald', 'start_date' => null,
            'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'Tell Penny to add cheese to the keyword list', 'https://hooks.slack.com/commands/fake');

    $document = Document::where('project_id', $this->project->id)->where('type', 'task')->first();
    $penny = User::where('first_name', 'Penny')->firstOrFail();

    expect($document->assignee_id)->toBe($penny->id);
});

it('falls back to the default extraction rule if the AiTemplate row is missing', function () {
    AiTemplate::where('type', 'slack_task_extraction')->delete();

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, 'typed by hand as a short command'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Task', 'priority' => null, 'task_status' => null, 'due_at' => null,
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'anything', 'https://hooks.slack.com/commands/fake');
});

// ── Job: task creation ───────────────────────────────────────────────────────

it('creates a task document from the extracted fields and posts an in-channel confirmation', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Follow up with client',
            'priority' => 'high',
            'task_status' => null,
            'due_at' => '2026-09-10',
            'assignee' => $this->user->name,
            'start_date' => null,
            'description' => 'Call the client about the contract renewal.',
            'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'follow up with the client', 'https://hooks.slack.com/commands/fake');

    $document = Document::where('project_id', $this->project->id)->where('type', 'task')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Follow up with client')
        ->and($document->priority)->toBe('high')
        ->and($document->due_at)->toBe('2026-09-10 00:00:00')
        ->and($document->assignee_id)->toBe($this->user->id)
        ->and($document->creator_id)->toBe($this->user->id)
        ->and($document->metadata['created_from'])->toBe('slack');

    Http::assertSent(function ($request) use ($document) {
        return $request->url() === 'https://hooks.slack.com/commands/fake'
            && $request['response_type'] === 'in_channel'
            && str_contains($request['text'], 'Follow up with client')
            && str_contains($request['text'], (string) $document->id);
    });
});

it('falls back to the raw command text when extraction fails, still creating a task', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'boom']);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'water the plants', 'https://hooks.slack.com/commands/fake');

    $document = Document::where('project_id', $this->project->id)->where('type', 'task')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('water the plants')
        ->and($document->priority)->toBe('medium')
        ->and($document->assignee_id)->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/commands/fake' && $request['response_type'] === 'in_channel');
});

it('posts an ephemeral error to response_url when the job fails', function () {
    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    $job = new CreateTaskFromSlackCommand($this->project, $this->user, 'follow up', 'https://hooks.slack.com/commands/fake');
    $job->failed(new \Exception('something broke'));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/commands/fake'
            && $request['response_type'] === 'ephemeral'
            && str_contains($request['text'], 'something broke');
    });
});

// ── Job: event extraction rule source ───────────────────────────────────────

it('uses the slack_event_extraction AiTemplate\'s user_prompt as the extraction rule', function () {
    AiTemplate::where('type', 'slack_event_extraction')
        ->update(['user_prompt' => 'CUSTOM EVENT RULE MARKER: extract only a title.']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, 'CUSTOM EVENT RULE MARKER'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Event', 'priority' => null, 'task_status' => null, 'due_at' => null,
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateEventFromSlackCommand::dispatchSync($this->project, $this->user, 'anything', 'https://hooks.slack.com/commands/fake');
});

it('falls back to the default event extraction rule if the AiTemplate row is missing', function () {
    AiTemplate::where('type', 'slack_event_extraction')->delete();

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, 'typed by hand as a short command'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Event', 'priority' => null, 'task_status' => null, 'due_at' => null,
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateEventFromSlackCommand::dispatchSync($this->project, $this->user, 'anything', 'https://hooks.slack.com/commands/fake');
});

// ── Job: event creation ──────────────────────────────────────────────────────

it('creates an event document from the extracted fields and posts an in-channel confirmation', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Team Offsite',
            'priority' => null,
            'task_status' => null,
            'due_at' => '2026-09-10',
            'assignee' => null,
            'start_date' => null,
            'description' => 'Annual team offsite at the lake house.',
            'tag' => 'offsite',
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateEventFromSlackCommand::dispatchSync($this->project, $this->user, 'team offsite next Thursday', 'https://hooks.slack.com/commands/fake');

    $document = Document::where('project_id', $this->project->id)->where('type', 'event')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Team Offsite')
        ->and($document->content)->toBe('Annual team offsite at the lake house.')
        // One date given (due_at) — the one-day-event rule fills start_at with the same date.
        ->and($document->start_at)->toBe('2026-09-10 00:00:00')
        ->and($document->due_at)->toBe('2026-09-10 00:00:00')
        ->and($document->creator_id)->toBe($this->user->id)
        ->and($document->metadata['created_from'])->toBe('slack')
        ->and($document->categories->pluck('name'))->toContain('offsite');

    Http::assertSent(function ($request) use ($document) {
        return $request->url() === 'https://hooks.slack.com/commands/fake'
            && $request['response_type'] === 'in_channel'
            && str_contains($request['text'], 'Team Offsite')
            && str_contains($request['text'], (string) $document->id);
    });
});

it('falls back to the raw command text when extraction fails, still creating an event', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'boom']);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateEventFromSlackCommand::dispatchSync($this->project, $this->user, 'company picnic', 'https://hooks.slack.com/commands/fake');

    $document = Document::where('project_id', $this->project->id)->where('type', 'event')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('company picnic')
        ->and($document->start_at)->toBeNull()
        ->and($document->due_at)->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/commands/fake' && $request['response_type'] === 'in_channel');
});

it('posts an ephemeral error to response_url when the event job fails', function () {
    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    $job = new CreateEventFromSlackCommand($this->project, $this->user, 'team offsite', 'https://hooks.slack.com/commands/fake');
    $job->failed(new \Exception('something broke'));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/commands/fake'
            && $request['response_type'] === 'ephemeral'
            && str_contains($request['text'], 'something broke');
    });
});
