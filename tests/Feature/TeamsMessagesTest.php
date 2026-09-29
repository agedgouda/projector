<?php

use App\Jobs\ReplyToTeamsConversation;
use App\Services\Teams\TeamsBotClient;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const TEAMS_APP_ID = 'fake-teams-app-id';
const TEAMS_SERVICE_URL = 'https://smba.trafficmanager.net/amer/';
const TEAMS_KID = 'fake-kid';

beforeEach(function () {
    config([
        'services.teams.app_id' => TEAMS_APP_ID,
        'services.teams.app_secret' => 'fake-secret',
        'services.teams.tenant_id' => 'home-tenant',
    ]);
    Cache::flush();

    $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $rsa = openssl_pkey_get_details($this->privateKey)['rsa'];

    Http::fake([
        'login.botframework.com/v1/.well-known/openidconfiguration' => Http::response(['jwks_uri' => 'https://login.botframework.com/v1/.well-known/keys']),
        'login.botframework.com/v1/.well-known/keys' => Http::response(['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => TEAMS_KID,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
            'endorsements' => ['msteams'],
        ]]]),
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-bot-token', 'expires_in' => 3600]),
        'smba.trafficmanager.net/*' => Http::response(['id' => 'activity-1'], 201),
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function teamsToken(OpenSSLAsymmetricKey $key, array $overrides = [], string $kid = TEAMS_KID): string
{
    $claims = array_merge([
        'iss' => 'https://api.botframework.com',
        'aud' => TEAMS_APP_ID,
        'serviceUrl' => TEAMS_SERVICE_URL,
        'nbf' => time() - 10,
        'exp' => time() + 3600,
    ], $overrides);

    openssl_pkey_export($key, $pem);

    return JWT::encode($claims, $pem, 'RS256', $kid);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function teamsActivity(array $overrides = []): array
{
    return array_merge([
        'type' => 'message',
        'channelId' => 'msteams',
        'serviceUrl' => TEAMS_SERVICE_URL,
        'text' => 'hello',
        'conversation' => ['id' => '19:abc@thread.tacv2;messageid=1', 'tenantId' => 'customer-tenant'],
    ], $overrides);
}

it('rejects a request with no bearer token', function () {
    $this->postJson('/teams/messages', teamsActivity())->assertUnauthorized();
});

it('rejects a token not signed by a Bot Connector key', function () {
    $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    $this->withToken(teamsToken($otherKey))->postJson('/teams/messages', teamsActivity())->assertUnauthorized();
});

it('rejects a token with the wrong claim', function (array $claims) {
    $this->withToken(teamsToken($this->privateKey, $claims))->postJson('/teams/messages', teamsActivity())->assertUnauthorized();
})->with([
    'wrong issuer' => [['iss' => 'https://evil.example.com']],
    'wrong audience' => [['aud' => 'someone-elses-bot']],
    'expired beyond clock skew' => [['nbf' => time() - 7200, 'exp' => time() - 600]],
    'mismatched serviceUrl' => [['serviceUrl' => 'https://evil.example.com/']],
]);

it('accepts a token within the five-minute clock skew', function () {
    Bus::fake();

    $this->withToken(teamsToken($this->privateKey, ['exp' => time() - 120]))
        ->postJson('/teams/messages', teamsActivity())
        ->assertOk();
});

it('rejects a key that is not endorsed for the activity channel', function () {
    $this->withToken(teamsToken($this->privateKey))
        ->postJson('/teams/messages', teamsActivity(['channelId' => 'webchat']))
        ->assertForbidden();
});

it('replies immediately and queues a delayed reply to a message', function () {
    Bus::fake();

    $this->withToken(teamsToken($this->privateKey))->postJson('/teams/messages', teamsActivity())->assertOk();

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://smba.trafficmanager.net/amer/v3/conversations/'.rawurlencode('19:abc@thread.tacv2;messageid=1').'/activities'
        && $request->hasHeader('Authorization', 'Bearer fake-bot-token')
        && $request['type'] === 'message');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'login.microsoftonline.com/home-tenant/oauth2/v2.0/token')
        && $request['scope'] === 'https://api.botframework.com/.default');

    Bus::assertDispatched(ReplyToTeamsConversation::class, fn (ReplyToTeamsConversation $job) => $job->conversationId === '19:abc@thread.tacv2;messageid=1'
        && $job->serviceUrl === TEAMS_SERVICE_URL
        && $job->tenantId === 'customer-tenant'
        && $job->delay !== null);
});

it('still acks and queues the delayed reply when the immediate reply is rejected', function () {
    Bus::fake();
    Log::spy();
    $deniedServiceUrl = 'https://denied.teams.example.com/';
    Http::fake(['denied.teams.example.com/*' => Http::response(['message' => 'Authorization has been denied for this request.'], 401)]);

    $this->withToken(teamsToken($this->privateKey, ['serviceUrl' => $deniedServiceUrl]))
        ->postJson('/teams/messages', teamsActivity(['serviceUrl' => $deniedServiceUrl]))
        ->assertOk();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'HTTP 401')
        && $context['conversation_tenant_id'] === 'customer-tenant'
        && $context['bot_home_tenant_id'] === 'home-tenant');
    Bus::assertDispatched(ReplyToTeamsConversation::class);
});

it('logs the install details from a conversationUpdate without replying', function () {
    Bus::fake();
    Log::spy();

    $this->withToken(teamsToken($this->privateKey))->postJson('/teams/messages', teamsActivity([
        'type' => 'conversationUpdate',
        'channelData' => ['team' => ['id' => '19:team@thread.tacv2', 'name' => 'Marketing'], 'tenant' => ['id' => 'customer-tenant']],
        'membersAdded' => [['id' => '28:bot']],
    ]))->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Teams conversationUpdate received'
        && $context['team_name'] === 'Marketing'
        && $context['tenant_id'] === 'customer-tenant');
    Bus::assertNotDispatched(ReplyToTeamsConversation::class);
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'smba.trafficmanager.net'));
});

it('delivers the queued reply and logs a rejection with the tenant involved', function () {
    Log::spy();
    $job = new ReplyToTeamsConversation(TEAMS_SERVICE_URL, 'conv-1', 'later', 'customer-tenant');

    $job->handle(app(TeamsBotClient::class));

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://smba.trafficmanager.net/amer/v3/conversations/conv-1/activities'
        && $request['text'] === 'later');

    $job->failed(new RuntimeException('Bot Connector rejected the message (HTTP 401)'));

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'HTTP 401')
        && $context['conversation_tenant_id'] === 'customer-tenant');
});

it('reuses the cached bot token across sends', function () {
    $client = app(TeamsBotClient::class);

    $client->sendToConversation(TEAMS_SERVICE_URL, 'conv-1', 'one');
    $client->sendToConversation(TEAMS_SERVICE_URL, 'conv-1', 'two');

    Http::assertSentCount(3);
});
