<?php

use App\Jobs\CreateEventFromSlackCommand;
use App\Jobs\CreateTaskFromSlackCommand;
use App\Models\Client;
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
        'bot_access_token' => 'xoxb-fake-token',
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

/**
 * Slack signs interactivity requests exactly like slash commands (HMAC over the raw body) —
 * the only difference is the body is a single form field, `payload`, whose value is JSON.
 */
function signSlackInteractivityRequest(string $body, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fake-signing-secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postSlackInteractivity(array $payload): \Illuminate\Testing\TestResponse
{
    $body = 'payload='.urlencode(json_encode($payload));
    $headers = signSlackInteractivityRequest($body);

    // call() (unlike post()/json()) doesn't apply withHeaders()'s defaultHeaders, so the
    // signature headers have to go straight into $server here instead. And Symfony's
    // Request::create() only populates the POST parameter bag from $parameters, never by
    // parsing $content — so both have to be passed, kept in sync, for getContent() (what the
    // signature covers) and input('payload') (what the controller reads) to agree.
    return test()->call('POST', '/slack/interactivity', ['payload' => json_encode($payload)], [], [], [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function messageShortcutPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'message_action',
        'callback_id' => 'create_task',
        'trigger_id' => 'trigger123',
        'team' => ['id' => 'T123'],
        'channel' => ['id' => 'C123'],
        'user' => ['id' => 'U123'],
        'message' => ['text' => 'follow up with the client about the contract'],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $metadataOverrides
 * @param  array<string, mixed>  $overrides
 */
function viewSubmissionPayload(array $metadataOverrides = [], string $text = 'follow up with the client', array $overrides = []): array
{
    $metadata = array_merge([
        'command' => '/task',
        'team_id' => 'T123',
        'channel_id' => 'C123',
        'slack_user_id' => 'U123',
    ], $metadataOverrides);

    return array_merge([
        'type' => 'view_submission',
        'view' => [
            'private_metadata' => json_encode($metadata),
            'state' => [
                'values' => [
                    'description_block' => [
                        'description_input' => ['value' => $text],
                    ],
                ],
            ],
        ],
    ], $overrides);
}

// ── Signature ────────────────────────────────────────────────────────────────

it('rejects an unsigned interactivity request', function () {
    $body = 'payload='.urlencode(json_encode(messageShortcutPayload()));

    test()->call('POST', '/slack/interactivity', [], [], [], [], $body)->assertUnauthorized();
});

// ── Message shortcut → modal open ───────────────────────────────────────────

it('opens an edit modal pre-filled with the message text', function () {
    Http::fake(['slack.com/api/views.open' => Http::response(['ok' => true], 200)]);

    postSlackInteractivity(messageShortcutPayload())->assertOk();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/views.open') {
            return false;
        }

        $view = $request['view'];

        return $request['trigger_id'] === 'trigger123'
            && $request->hasHeader('Authorization', 'Bearer xoxb-fake-token')
            && $view['blocks'][0]['element']['initial_value'] === 'follow up with the client about the contract';
    });
});

it('opens an error modal for an unbound channel instead of the edit modal', function () {
    Http::fake(['slack.com/api/views.open' => Http::response(['ok' => true], 200)]);

    postSlackInteractivity(messageShortcutPayload(['channel' => ['id' => 'C-unbound']]))->assertOk();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/views.open') {
            return false;
        }

        $text = $request['view']['blocks'][0]['text']['text'] ?? '';

        return str_contains($text, "isn't bound to a project yet");
    });
});

it('opens an error modal for an unlinked slack user instead of the edit modal', function () {
    Http::fake(['slack.com/api/views.open' => Http::response(['ok' => true], 200)]);

    postSlackInteractivity(messageShortcutPayload(['user' => ['id' => 'U-unlinked']]))->assertOk();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/views.open') {
            return false;
        }

        $text = $request['view']['blocks'][0]['text']['text'] ?? '';

        return str_contains($text, 'Connect your Slack account in Projector first');
    });
});

it('builds the event shortcut modal with an event-specific title', function () {
    Http::fake(['slack.com/api/views.open' => Http::response(['ok' => true], 200)]);

    postSlackInteractivity(messageShortcutPayload(['callback_id' => 'create_event', 'message' => ['text' => 'team offsite next Thursday']]))->assertOk();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/views.open') {
            return false;
        }

        return $request['view']['title']['text'] === 'Create Event'
            && $request['view']['blocks'][0]['element']['initial_value'] === 'team offsite next Thursday';
    });
});

it('does nothing for an unknown callback_id', function () {
    Http::fake();

    postSlackInteractivity(messageShortcutPayload(['callback_id' => 'something_else']))->assertOk();

    Http::assertNothingSent();
});

// ── View submission → job dispatch ──────────────────────────────────────────

it('dispatches the task job from a view submission, replying via chat.postMessage', function () {
    Bus::fake();

    postSlackInteractivity(viewSubmissionPayload())->assertOk();

    Bus::assertDispatched(CreateTaskFromSlackCommand::class, function (CreateTaskFromSlackCommand $job) {
        return $job->project->is($this->project)
            && $job->user->is($this->user)
            && $job->text === 'follow up with the client'
            && $job->responseUrl === null
            && $job->slackBotToken === 'xoxb-fake-token'
            && $job->slackChannelId === 'C123';
    });
});

it('dispatches the event job from a view submission when the metadata command is /events', function () {
    Bus::fake();

    postSlackInteractivity(viewSubmissionPayload(['command' => '/events'], 'team offsite next Thursday'))->assertOk();

    Bus::assertDispatched(CreateEventFromSlackCommand::class, function (CreateEventFromSlackCommand $job) {
        return $job->project->is($this->project)
            && $job->text === 'team offsite next Thursday'
            && $job->slackBotToken === 'xoxb-fake-token'
            && $job->slackChannelId === 'C123';
    });

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('drops the submission silently if the channel binding was removed before submit', function () {
    Bus::fake();

    postSlackInteractivity(viewSubmissionPayload(['channel_id' => 'C-unbound']))->assertOk();

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('drops the submission silently if the identity was unlinked before submit', function () {
    Bus::fake();

    postSlackInteractivity(viewSubmissionPayload(['slack_user_id' => 'U-unlinked']))->assertOk();

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});

it('drops the submission silently if the edited text was left blank', function () {
    Bus::fake();

    postSlackInteractivity(viewSubmissionPayload([], ''))->assertOk();

    Bus::assertNotDispatched(CreateTaskFromSlackCommand::class);
});
