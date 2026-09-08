<?php

use App\Jobs\ImportSlackFile;
use App\Models\Client;
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
    config(['services.slack.signing_secret' => 'fake-signing-secret']);
});

function signSlackRequest(string $body, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fake-signing-secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

it('answers the url_verification handshake when correctly signed', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];
    $body = json_encode($payload);

    $this->withHeaders(signSlackRequest($body))
        ->postJson('/slack/events', $payload)
        ->assertOk()
        ->assertJson(['challenge' => 'abc123']);
});

it('rejects a request with an invalid signature', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];

    $this->withHeaders([
        'X-Slack-Request-Timestamp' => (string) time(),
        'X-Slack-Signature' => 'v0=not-a-real-signature',
    ])
        ->postJson('/slack/events', $payload)
        ->assertUnauthorized();
});

it('rejects a request missing the signature headers', function () {
    $this->postJson('/slack/events', ['type' => 'url_verification', 'challenge' => 'abc123'])
        ->assertUnauthorized();
});

it('rejects a stale request even with a valid signature', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];
    $body = json_encode($payload);
    $staleTimestamp = time() - 600;

    $this->withHeaders(signSlackRequest($body, $staleTimestamp))
        ->postJson('/slack/events', $payload)
        ->assertUnauthorized();
});

it('acknowledges an unhandled event type with a 204', function () {
    $payload = ['type' => 'event_callback', 'event' => ['type' => 'message']];
    $body = json_encode($payload);

    $this->withHeaders(signSlackRequest($body))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();
});

// ── file_share message → event import ───────────────────────────────────────

function bindSlackChannelToProject(): array
{
    $org = Organization::create(['name' => 'Test Org']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'org-admin']);

    $workspace = SlackWorkspace::factory()->create([
        'organization_id' => $org->id,
        'team_id' => 'T123',
        'bot_access_token' => 'xoxb-fake-token',
    ]);

    $binding = SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $workspace->id,
        'channel_id' => 'C123',
        'project_id' => $project->id,
    ]);

    return compact('org', 'project', 'user', 'workspace', 'binding');
}

/**
 * @param  array<string, mixed>  $fileOverrides
 * @param  array<string, mixed>  $eventOverrides
 */
function fileShareEventPayload(array $fileOverrides = [], array $eventOverrides = []): array
{
    $event = array_merge([
        'type' => 'message',
        'subtype' => 'file_share',
        'channel' => 'C123',
        'user' => 'U123',
        'files' => [array_merge([
            'name' => 'events.csv',
            'url_private_download' => 'https://files.slack.com/files-pri/T123-F123/events.csv',
            'mimetype' => 'text/csv',
        ], $fileOverrides)],
    ], $eventOverrides);

    return ['type' => 'event_callback', 'team_id' => 'T123', 'event' => $event];
}

it('dispatches an import job for a spreadsheet file shared in a bound channel by a linked user', function () {
    Bus::fake();
    $fixture = bindSlackChannelToProject();
    SlackUserIdentity::factory()->create(['user_id' => $fixture['user']->id, 'slack_team_id' => 'T123', 'slack_user_id' => 'U123']);

    $payload = fileShareEventPayload();
    $this->withHeaders(signSlackRequest(json_encode($payload)))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();

    Bus::assertDispatched(ImportSlackFile::class, function ($job) use ($fixture) {
        return $job->project->is($fixture['project'])
            && $job->user->is($fixture['user'])
            && $job->slackFile['name'] === 'events.csv'
            && $job->slackBotToken === 'xoxb-fake-token'
            && $job->slackChannelId === 'C123';
    });
});

it('ignores a non-spreadsheet, non-document file shared in a bound channel', function () {
    Bus::fake();
    $fixture = bindSlackChannelToProject();
    SlackUserIdentity::factory()->create(['user_id' => $fixture['user']->id, 'slack_team_id' => 'T123', 'slack_user_id' => 'U123']);

    $payload = fileShareEventPayload(['name' => 'screenshot.png']);
    $this->withHeaders(signSlackRequest(json_encode($payload)))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportSlackFile::class);
});

it('dispatches an import job for a docx file shared in a bound channel', function () {
    Bus::fake();
    $fixture = bindSlackChannelToProject();
    SlackUserIdentity::factory()->create(['user_id' => $fixture['user']->id, 'slack_team_id' => 'T123', 'slack_user_id' => 'U123']);

    $payload = fileShareEventPayload(['name' => 'schedule.docx']);
    $this->withHeaders(signSlackRequest(json_encode($payload)))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();

    Bus::assertDispatched(ImportSlackFile::class, fn ($job) => $job->slackFile['name'] === 'schedule.docx');
});

it('ignores a file shared in an unbound channel', function () {
    Bus::fake();
    bindSlackChannelToProject();

    $payload = fileShareEventPayload(eventOverrides: ['channel' => 'C-unbound']);
    $this->withHeaders(signSlackRequest(json_encode($payload)))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportSlackFile::class);
});

it('tells an unlinked uploader to connect their slack account instead of importing', function () {
    Bus::fake();
    bindSlackChannelToProject();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $payload = fileShareEventPayload();
    $this->withHeaders(signSlackRequest(json_encode($payload)))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportSlackFile::class);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'C123'
            && str_contains($request['text'], 'Connect your Slack account');
    });
});
